{* webnic_dns admin: partner zone statistics + premium usage (read-only) *}
<div style="padding:10px;">
    <div class="sectionhead_ext open">Zone Statistics</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11">WebNIC DNS v2: <code>GET /dns/v2/statistics</code></p>
        {if $wn_stats}
        <table class="table table-condensed" style="width:auto;">
            <tr><td><b class="fs11">Total basic NS zones</b></td><td>{$wn_stats.totalBasicNs}</td></tr>
            <tr><td><b class="fs11">Total premium NS zones</b></td><td>{$wn_stats.totalPremiumNs}</td></tr>
            <tr><td><b class="fs11">Total georoute zones</b></td><td>{$wn_stats.totalGeoroute}</td></tr>
        </table>
        {else}
            <p class="fs11"><i>No statistics available (or credentials not yet valid).</i></p>
        {/if}
    </div>

    <div class="sectionhead_ext open" style="margin-top:10px;">Premium Subscription Usage</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>GET /dns/v2/premium-subscription/statistics</code></p>
        {if $wn_premium_stats}
        <table class="table table-condensed" style="width:auto;">
            <thead><tr><th class="fs11">Date</th><th class="fs11">Records</th></tr></thead>
            <tbody>
            {foreach from=$wn_premium_stats item=row}
                <tr><td>{$row.activityDate|escape}</td><td>{$row.records}</td></tr>
            {/foreach}
            </tbody>
        </table>
        {else}
            <p class="fs11"><i>No premium usage data.</i></p>
        {/if}
    </div>
</div>
