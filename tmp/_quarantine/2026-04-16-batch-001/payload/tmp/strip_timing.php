<?php
$d = json_decode(file_get_contents('simulation_output/current-db/export/current_season.json'), true);
$strip = [
    'season_id','start_time','end_time','blackout_time','last_processed_tick',
    'blackout_started_tick','blackout_star_price_snapshot','status','season_expired',
    'expiration_finalized','current_star_price','market_anchor_price',
    'pending_star_burn_coins','star_burn_ema_fp','net_mint_ema_fp','market_pressure_fp',
    'total_coins_supply','total_coins_supply_end_of_tick','coins_active_total',
    'coins_idle_total','coins_offline_total','effective_price_supply','created_at','season_seed_hex',
];
foreach ($strip as $k) {
    unset($d[$k]);
}
file_put_contents(
    'simulation_output/current-db/export/current_season_economy_only.json',
    json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo count($d) . " keys written\n";
