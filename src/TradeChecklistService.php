<?php

declare(strict_types=1);

namespace Stocks;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Experience-style checklist: UK lead + RSI + volume. Bias only, not advice.
 */
final class TradeChecklistService
{
    public function __construct(
        private readonly LiveBiasService $liveBias,
        private readonly RsiAnalyzer $rsi,
        private readonly PriceRepository $repo,
        private readonly YahooFinanceClient $yahoo
    ) {
    }

    /**
     * @param list<array<string,mixed>> $symbolMeta
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function build(
        array $symbolMeta,
        array $config,
        string $ukSymbol = 'EQQQ',
        string $usSymbol = 'SOXL',
        float $thresholdPct = 0.3,
        bool $syncLive = true
    ): array {
        $ukSymbol = strtoupper($ukSymbol);
        $usSymbol = strtoupper($usSymbol);
        if (!in_array($usSymbol, ['SOXL', 'QQQ'], true)) {
            $usSymbol = 'SOXL';
        }

        $sync = $syncLive
            ? $this->syncSymbols($symbolMeta, $config, [$ukSymbol, $usSymbol, 'QQQ', 'SOXL', 'EQQQ'])
            : ['ran' => false, 'symbols' => []];

        $live = $this->liveBias->build($symbolMeta, $ukSymbol, $usSymbol, $thresholdPct);
        if (($live['ok'] ?? false) !== true) {
            return $live;
        }

        $bars = $this->repo->barsForSymbol($usSymbol);
        $snap = $this->rsi->latestSnapshot($bars, $usSymbol, 5, 14);

        $markets = $this->marketClocks();
        $lead = $live['lead'] ?? [];
        $leadDir = (string) ($lead['direction'] ?? 'flat');
        $leadPct = $lead['pct'] ?? null;
        $rsiVal = $snap['rsi'] ?? null;
        $volZ = $snap['vol_z'] ?? null;

        $items = [];

        // 1) Markets open?
        $anyOpen = ($markets['london']['open'] ?? false) || ($markets['new_york']['open'] ?? false);
        $items[] = [
            'id' => 'session',
            'label' => 'Market session',
            'pass' => $anyOpen,
            'detail' => $anyOpen
                ? 'At least one of London / New York is in regular hours.'
                : 'Weekend or outside London/NY hours — live Monday from London ~08:00, US ~09:30 ET.',
            'value' => $anyOpen ? 'OPEN' : 'CLOSED',
        ];

        // 2) UK lead clear
        $leadClear = $leadDir === 'up' || $leadDir === 'down';
        $items[] = [
            'id' => 'uk_lead',
            'label' => 'UK lead clear',
            'pass' => $leadClear,
            'detail' => $leadPct === null
                ? 'No UK quote yet.'
                : sprintf('%s %+0.2f%% (%s)', $ukSymbol, $leadPct, $leadDir),
            'value' => $leadDir,
        ];

        // 3) Historical UK→US odds agree (≥55% follow)
        $follow = $live['odds']['follow_pct'] ?? null;
        $histOk = $leadClear && $follow !== null && $follow >= 55;
        $items[] = [
            'id' => 'history',
            'label' => 'History agrees (≥55%)',
            'pass' => $histOk,
            'detail' => $follow === null
                ? 'No matching history days.'
                : sprintf('Follow lead historically %s%% (N=%s, %s)', $follow, $live['odds']['n'] ?? 0, $live['odds']['strength'] ?? ''),
            'value' => $follow !== null ? $follow . '%' : '—',
        ];

        // 4) RSI timing
        $rsiPass = false;
        $rsiDetail = 'RSI unavailable';
        if ($rsiVal !== null) {
            if ($leadDir === 'up') {
                // For long bias, prefer not overbought; oversold is a plus
                $rsiPass = $rsiVal <= 40;
                $rsiDetail = sprintf('RSI %.1f — for UP lead, want ≤40 (room to bounce/extend)', $rsiVal);
            } elseif ($leadDir === 'down') {
                $rsiPass = $rsiVal >= 60;
                $rsiDetail = sprintf('RSI %.1f — for DOWN lead, want ≥60 (room to fade)', $rsiVal);
            } else {
                $rsiPass = $rsiVal <= 30 || $rsiVal >= 70;
                $rsiDetail = sprintf('RSI %.1f — flat lead; only extreme RSI counts', $rsiVal);
            }
        }
        $items[] = [
            'id' => 'rsi',
            'label' => 'RSI timing (' . $usSymbol . ' 5m)',
            'pass' => $rsiPass,
            'detail' => $rsiDetail,
            'value' => $rsiVal !== null ? round($rsiVal, 1) : '—',
        ];

        // 5) Volume confirmation
        $volPass = $volZ !== null && $volZ >= 1.0;
        $items[] = [
            'id' => 'volume',
            'label' => 'Volume confirmation',
            'pass' => $volPass,
            'detail' => $volZ === null
                ? 'Volume z-score unavailable'
                : sprintf('Vol z-score %.2f (want ≥ +1.0 for conviction)', $volZ),
            'value' => $volZ !== null ? round($volZ, 2) : '—',
        ];

        // 6) Signals not fighting each other
        $aligned = false;
        if ($leadDir === 'up' && $rsiVal !== null && $rsiVal < 70) {
            $aligned = true;
        } elseif ($leadDir === 'down' && $rsiVal !== null && $rsiVal > 30) {
            $aligned = true;
        } elseif ($leadDir === 'flat') {
            $aligned = false;
        }
        // Conflict if UK up but RSI deeply overbought, or UK down but deeply oversold (chase)
        $conflict = false;
        if ($leadDir === 'up' && $rsiVal !== null && $rsiVal >= 75) {
            $conflict = true;
            $aligned = false;
        }
        if ($leadDir === 'down' && $rsiVal !== null && $rsiVal <= 25) {
            $conflict = true;
            $aligned = false;
        }
        $items[] = [
            'id' => 'align',
            'label' => 'No major conflict',
            'pass' => $aligned && !$conflict,
            'detail' => $conflict
                ? 'Lead and RSI conflict (possible chase) — wait.'
                : ($aligned ? 'Lead and RSI not fighting.' : 'Not aligned yet.'),
            'value' => $conflict ? 'CONFLICT' : ($aligned ? 'OK' : 'WAIT'),
        ];

        $passCount = count(array_filter($items, static fn ($i) => $i['pass']));
        $total = count($items);

        $action = 'WAIT';
        if ($conflict || !$anyOpen) {
            $action = 'WAIT';
        } elseif ($passCount >= 4 && $leadDir === 'up') {
            $action = 'LEAN_LONG';
        } elseif ($passCount >= 4 && $leadDir === 'down') {
            $action = 'LEAN_SHORT';
        } elseif ($passCount >= 3 && $leadClear) {
            $action = $leadDir === 'up' ? 'LEAN_LONG_WEAK' : 'LEAN_SHORT_WEAK';
        }

        $summary = match ($action) {
            'LEAN_LONG' => "Checklist LEAN LONG ({$passCount}/{$total} pass) — UK up, history/RSI/volume supportive. Bias only; use a stop.",
            'LEAN_SHORT' => "Checklist LEAN SHORT ({$passCount}/{$total} pass) — UK down, history/RSI/volume supportive. Bias only; use a stop.",
            'LEAN_LONG_WEAK' => "Weak lean LONG ({$passCount}/{$total}) — incomplete checklist. Prefer wait or tiny size.",
            'LEAN_SHORT_WEAK' => "Weak lean SHORT ({$passCount}/{$total}) — incomplete checklist. Prefer wait or tiny size.",
            default => $anyOpen
                ? "WAIT ({$passCount}/{$total} pass) — need clearer UK lead + RSI/volume agreement."
                : "WAIT — markets closed. Live checklist updates from Monday: London ~08:00, US cash 09:30 ET.",
        };

        return [
            'ok' => true,
            'action' => $action,
            'pass_count' => $passCount,
            'total' => $total,
            'uk_symbol' => $ukSymbol,
            'us_symbol' => $usSymbol,
            'threshold_pct' => $thresholdPct,
            'items' => $items,
            'markets' => $markets,
            'live' => [
                'bias' => $live['bias'] ?? 'neutral',
                'lead' => $lead,
                'odds' => $live['odds'] ?? [],
                'quotes' => $live['quotes'] ?? [],
                'summary_text' => $live['summary_text'] ?? '',
            ],
            'rsi_snapshot' => $snap,
            'sync' => $sync,
            'summary_text' => $summary,
            'disclaimer' => 'Experience checklist only — not trade advice or a prediction. Quotes may be delayed. Small history N = weak.',
            'as_of_utc' => gmdate('Y-m-d H:i:s'),
            'monday_ready' => true,
            'refresh_seconds' => $anyOpen ? 20 : 60,
        ];
    }

    /**
     * @param list<array<string,mixed>> $symbolMeta
     * @param array<string,mixed> $config
     * @param list<string> $wanted
     * @return array<string,mixed>
     */
    private function syncSymbols(array $symbolMeta, array $config, array $wanted): array
    {
        $wanted = array_values(array_unique(array_map('strtoupper', $wanted)));
        $by = [];
        foreach ($symbolMeta as $m) {
            $by[strtoupper((string) $m['symbol'])] = SymbolSessions::normalize($m, $config);
        }

        $out = [];
        foreach ($wanted as $sym) {
            if (!isset($by[$sym])) {
                continue;
            }
            $row = $by[$sym];
            try {
                $session = SymbolSessions::filterFor($row);
                $raw = $this->yahoo->fetchRecent($row['yahoo_symbol'], '2d');
                $bars = $session->filter($raw);
                $n = $this->repo->upsertBars($sym, $bars);
                $out[] = [
                    'symbol' => $sym,
                    'upserted' => $n,
                    'latest' => $this->repo->latestTs($sym),
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'symbol' => $sym,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
            usleep(150000);
        }

        return ['ran' => true, 'symbols' => $out];
    }

    /** @return array<string,mixed> */
    private function marketClocks(): array
    {
        $mk = static function (string $tzName, string $start, string $end, string $label): array {
            $tz = new DateTimeZone($tzName);
            $now = new DateTimeImmutable('now', $tz);
            $wd = (int) $now->format('N');
            $hm = ((int) $now->format('H')) * 60 + (int) $now->format('i');
            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            $open = $wd <= 5 && $hm >= ($sh * 60 + $sm) && $hm < ($eh * 60 + $em);
            return [
                'label' => $label,
                'timezone' => $tzName,
                'local_time' => $now->format('Y-m-d H:i T'),
                'weekday' => $wd,
                'session' => "{$start}–{$end}",
                'open' => $open,
            ];
        };

        return [
            'london' => $mk('Europe/London', '08:00', '16:30', 'London'),
            'new_york' => $mk('America/New_York', '09:30', '16:00', 'New York'),
        ];
    }
}
