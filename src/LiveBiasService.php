<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Near-live prices + historical UK→US bias (not a prediction).
 */
final class LiveBiasService
{
    public function __construct(
        private readonly YahooFinanceClient $yahoo,
        private readonly GlobalLeadLag $leadLag
    ) {
    }

    /**
     * @param list<array<string,mixed>> $symbolMeta
     * @return array<string,mixed>
     */
    public function build(
        array $symbolMeta,
        string $ukSymbol = 'EQQQ',
        string $usSymbol = 'QQQ',
        float $thresholdPct = 0.3
    ): array {
        $ukSymbol = strtoupper($ukSymbol);
        $usSymbol = strtoupper($usSymbol);
        $thresholdPct = abs($thresholdPct);

        $bySymbol = [];
        foreach ($symbolMeta as $m) {
            $bySymbol[strtoupper((string) $m['symbol'])] = $m;
        }

        $ukMeta = $bySymbol[$ukSymbol] ?? null;
        $usMeta = $bySymbol[$usSymbol] ?? null;
        if ($ukMeta === null || $usMeta === null) {
            return [
                'ok' => false,
                'error' => 'Unknown UK/US symbol for live bias',
            ];
        }

        $quotes = [];
        $quoteErrors = [];
        foreach ([$ukSymbol => $ukMeta, $usSymbol => $usMeta] as $sym => $meta) {
            try {
                $q = $this->yahoo->fetchQuote((string) $meta['yahoo_symbol']);
                $q['symbol'] = $sym;
                $q['name'] = (string) $meta['name'];
                $q['region'] = (string) ($meta['region'] ?? '');
                $quotes[$sym] = $q;
            } catch (\Throwable $e) {
                $quoteErrors[$sym] = $e->getMessage();
            }
        }

        // Also fetch the other US name for the panel when useful
        foreach (['QQQ', 'SOXL'] as $extra) {
            if (isset($quotes[$extra]) || !isset($bySymbol[$extra])) {
                continue;
            }
            try {
                $q = $this->yahoo->fetchQuote((string) $bySymbol[$extra]['yahoo_symbol']);
                $q['symbol'] = $extra;
                $q['name'] = (string) $bySymbol[$extra]['name'];
                $q['region'] = (string) ($bySymbol[$extra]['region'] ?? 'us');
                $quotes[$extra] = $q;
            } catch (\Throwable $e) {
                $quoteErrors[$extra] = $e->getMessage();
            }
        }

        $hist = $this->leadLag->analyze($symbolMeta, $ukSymbol, $usSymbol, $thresholdPct);

        $ukQuote = $quotes[$ukSymbol] ?? null;
        $leadPct = null;
        $leadSource = null;
        if ($ukQuote !== null) {
            if ($ukQuote['session_change_pct'] !== null) {
                $leadPct = (float) $ukQuote['session_change_pct'];
                $leadSource = 'session_open';
            } else {
                $leadPct = (float) $ukQuote['change_pct'];
                $leadSource = 'prev_close';
            }
        }

        $direction = 'flat';
        if ($leadPct !== null) {
            if ($leadPct >= $thresholdPct) {
                $direction = 'up';
            } elseif ($leadPct <= -$thresholdPct) {
                $direction = 'down';
            }
        }

        $matched = null;
        foreach ($hist['scenarios'] ?? [] as $s) {
            if ($direction === 'up' && $s['id'] === 'uk_up') {
                $matched = $s;
                break;
            }
            if ($direction === 'down' && $s['id'] === 'uk_down') {
                $matched = $s;
                break;
            }
        }

        $bias = 'neutral';
        $leanPct = null;
        $againstPct = null;
        $avgUs = null;
        $n = 0;
        $strength = 'too_few';
        if ($matched !== null && ($matched['n'] ?? 0) > 0) {
            $n = (int) $matched['n'];
            $strength = (string) $matched['strength'];
            $leanPct = $direction === 'up' ? $matched['us_up_pct'] : $matched['us_down_pct'];
            $againstPct = $direction === 'up' ? $matched['us_down_pct'] : $matched['us_up_pct'];
            $avgUs = $matched['avg_us_ret'];
            if ($leanPct !== null && $leanPct >= 55) {
                $bias = $direction === 'up' ? 'lean_up' : 'lean_down';
            } elseif ($leanPct !== null && $leanPct <= 45) {
                $bias = $direction === 'up' ? 'lean_down' : 'lean_up';
            }
        } elseif ($direction === 'flat') {
            $bias = 'neutral';
        }

        $disclaimer = 'Historical bias only — not a prediction or trade advice. '
            . 'Live quotes from Yahoo may be delayed. Small N means weak signal.';

        $summary = $this->summaryText(
            $ukSymbol,
            $usSymbol,
            $leadPct,
            $direction,
            $thresholdPct,
            $bias,
            $leanPct,
            $n,
            $strength,
            $avgUs
        );

        return [
            'ok' => true,
            'uk_symbol' => $ukSymbol,
            'us_symbol' => $usSymbol,
            'threshold_pct' => $thresholdPct,
            'quotes' => array_values($quotes),
            'quote_errors' => $quoteErrors,
            'lead' => [
                'symbol' => $ukSymbol,
                'pct' => $leadPct,
                'source' => $leadSource,
                'direction' => $direction,
            ],
            'bias' => $bias,
            'history_match' => $matched,
            'odds' => [
                'follow_pct' => $leanPct,
                'against_pct' => $againstPct,
                'avg_us_ret' => $avgUs,
                'n' => $n,
                'strength' => $strength,
                'same_dir_pct' => $hist['agreement']['uk_us_same_dir_pct'] ?? null,
                'corr_uk_us' => $hist['agreement']['corr_uk_us'] ?? null,
            ],
            'summary_text' => $summary,
            'disclaimer' => $disclaimer,
            'as_of_utc' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function summaryText(
        string $uk,
        string $us,
        ?float $leadPct,
        string $direction,
        float $thresholdPct,
        string $bias,
        ?float $leanPct,
        int $n,
        string $strength,
        ?float $avgUs
    ): string {
        if ($leadPct === null) {
            return "Could not read a live UK lead quote for {$uk}.";
        }
        $leadStr = sprintf('%+.2f', $leadPct);
        if ($direction === 'flat') {
            return "{$uk} is roughly flat ({$leadStr}%, under ±{$thresholdPct}% threshold) — no directional bias vs history.";
        }
        $dirWord = $direction === 'up' ? 'up' : 'down';
        if ($n === 0) {
            return "{$uk} is {$dirWord} {$leadStr}% now, but not enough matching history days yet.";
        }
        $followWord = $direction === 'up' ? 'finished up' : 'finished down';
        $avgStr = $avgUs !== null ? sprintf('%+.2f', $avgUs) : 'n/a';
        $biasWord = match ($bias) {
            'lean_up' => 'Lean UP',
            'lean_down' => 'Lean DOWN',
            default => 'Neutral',
        };
        return "{$biasWord}: {$uk} is {$dirWord} {$leadStr}% → historically {$us} {$followWord} "
            . ($leanPct ?? '—') . "% of similar days (N={$n}, {$strength}, avg US {$avgStr}%).";
    }
}
