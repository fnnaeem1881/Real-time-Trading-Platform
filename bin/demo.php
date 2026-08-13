<?php

declare(strict_types=1);

/**
 * End-to-end CLI demo: runs the full pipeline for N steps and prints a report
 * touching every milestone. Proves the core works with zero infrastructure.
 *
 * Usage:  php bin/demo.php [steps] [seed]
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Engine\TradingPlatform;

$steps = (int) ($argv[1] ?? 500);
$seed = (int) ($argv[2] ?? 42);

$c = fn (string $s, string $col) => "\033[{$col}m{$s}\033[0m";
$h = fn (string $s) => "\n".$c('▎ '.$s, '1;36')."\n";

echo $c("╔══════════════════════════════════════════════════════════╗\n", '36');
echo $c('║   Real-time Trading Platform — end-to-end demo run        ║'."\n", '36');
echo $c("╚══════════════════════════════════════════════════════════╝\n", '36');
echo "seed={$seed}  steps={$steps}\n";

$t0 = hrtime(true);
$p = new TradingPlatform($seed);
$p->runTo($steps);
$elapsed = (hrtime(true) - $t0) / 1e6;
$s = $p->snapshot();

echo $h('M1 · Market Data & Analysis');
printf("  fair value      %s\n", number_format($s['fairValue'], 2));
printf("  last price      %s   spread %s\n", number_format($s['lastPrice'], 2), $s['bbo']['spread']);
printf("  RSI(14) %s   MACD %s   VWAP %s\n", round($s['indicators']['rsi'] ?? 0, 1), round($s['indicators']['macd'] ?? 0, 2), round($s['indicators']['vwap'] ?? 0, 2));
printf("  BTC vol %s%%   ETH vol %s%%   corr(BTC,ETH) %s\n", $s['stats']['volBtcPct'], $s['stats']['volEthPct'], $s['stats']['correlation']);

echo $h('M2 · Trading Engine & Order Management');
printf("  matching algo   %s\n", $s['totals']['algorithm']);
printf("  trades          %s   volume %s\n", $s['totals']['trades'], $s['totals']['volume']);
printf("  book depth      %d bid levels / %d ask levels\n", count($s['book']['bids']), count($s['book']['asks']));

echo $h('M3 · Risk & Compliance');
printf("  VaR95 %s   CVaR95 %s   VaR99 %s\n", round($s['risk']['var95'], 2), round($s['risk']['cvar95'], 2), round($s['risk']['var99'], 2));
printf("  gross exposure  %s   drawdown %s%%\n", number_format($s['risk']['grossExposure'], 2), round($s['risk']['drawdown'] * 100, 2));
printf("  kill-switch     %s   rejected orders %d\n", $s['risk']['killSwitch'] ? 'ACTIVE' : 'off', $s['totals']['rejected']);
printf("  audit chain     %s (%d entries, head %s)\n", $s['compliance']['auditValid'] ? $c('VALID', '32') : $c('TAMPERED', '31'), $s['compliance']['auditCount'], $s['compliance']['auditHead']);
printf("  MiFID reports   %d   surveillance flags %d\n", $s['compliance']['mifidReports'], $s['compliance']['surveillanceFlags']);

echo $h('M4 · Analytics & Performance');
printf("  equity          %s\n", number_format($s['pnl']['equity'], 2));
printf("  total P&L       %s   return %s%%\n", ($s['pnl']['totalPnl'] >= 0 ? '+' : '').number_format($s['pnl']['totalPnl'], 2), round($s['pnl']['totalReturnPct'], 3));
printf("  Sharpe %s   Sortino %s   maxDD %s%%\n", round($s['pnl']['sharpe'], 2), round($s['pnl']['sortino'], 2), round($s['pnl']['maxDrawdown'] * 100, 2));
foreach ($s['attribution'] as $name => $a) {
    printf("  %-14s realized %s over %d fills\n", $name, ($a['realized'] >= 0 ? '+' : '').number_format($a['realized'], 2), $a['fills']);
}
printf("  match latency   p50 %sµs  p99 %sµs  (pool reuse %s%%)\n", $s['perf']['latency']['p50Us'], $s['perf']['latency']['p99Us'], round($s['perf']['objectPool']['reuseRate'] * 100, 1));

echo "\n".$c(sprintf('▶ simulated %d steps in %.0f ms  (%.0f steps/sec)', $steps, $elapsed, $steps / ($elapsed / 1000)), '1;32')."\n";
echo "  Launch the live dashboard:  ".$c('composer serve', '33')."  →  http://127.0.0.1:8080\n\n";
