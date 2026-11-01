{* webnic_dns admin: premium subscription + partner + NS + forwarding tools *}
{literal}
<script type="text/javascript">
if (typeof window.wnPost !== 'function') {
    window.wnPost = function (fields) {
        var f = document.createElement('form');
        f.method = 'POST'; f.action = window.location.href;
        for (var k in fields) { if (!fields.hasOwnProperty(k)) continue;
            var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; f.appendChild(i); }
        document.body.appendChild(f); f.submit();
    };
}
function wnZoneAction(action) {
    wnPost({ webnic_action: action,
        wn_zone: document.getElementById('wn_sub_zone').value,
        wn_product: document.getElementById('wn_sub_product').value,
        wn_term: document.getElementById('wn_sub_term').value,
        wn_auto_renew: document.getElementById('wn_sub_autorenew').checked ? 1 : 0 });
    return false;
}
function wnSubAction(action, sid) {
    wnPost({ webnic_action: action, wn_subscription_id: sid,
        wn_term: document.getElementById('wn_sub_term').value });
    return false;
}
function wnFwdAction(action) {
    wnPost({ webnic_action: action,
        wn_zone: document.getElementById('wn_fwd_zone').value,
        wn_subdomain: document.getElementById('wn_fwd_subdomain').value,
        wn_target_url: document.getElementById('wn_fwd_url').value,
        wn_user: document.getElementById('wn_fwd_user').value,
        wn_target_email: document.getElementById('wn_fwd_email').value,
        wn_override: document.getElementById('wn_fwd_override').checked ? 1 : 0 });
    return false;
}
</script>
{/literal}
<div style="padding:10px;">
    <div class="sectionhead_ext open">Per-zone Subscription Tools</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11">Subscribe/upgrade a zone to premium, or attach it to the partner / NS subscription.</p>
        <div><b class="fs11">Zone (domain)</b><br/><input type="text" id="wn_sub_zone" class="inp" style="width:250px;"/></div>
        <div style="margin-top:4px;">
            <b class="fs11">Product</b>
            <select id="wn_sub_product" class="inp" style="width:200px;">
                <option value="premium_ns_georoute">premium_ns_georoute</option>
                <option value="premium_ns_partner">premium_ns_partner</option>
                <option value="basic_ns">basic_ns</option>
            </select>
            <b class="fs11">Term</b>
            <select id="wn_sub_term" class="inp" style="width:70px;"><option value="12">12</option><option value="1">1</option></select>
            <input type="checkbox" id="wn_sub_autorenew"/> <span class="fs11">auto-renew</span>
        </div>
        <div style="margin-top:8px;">
            <button type="button" class="btn btn-success btn-sm" onclick="return wnZoneAction('sub_upgrade');" title="POST /dns/v2/subscription/standalone-domain">Upgrade to premium</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="return wnZoneAction('sub_subscribe');">Subscribe</button>
            <button type="button" class="btn btn-default btn-sm" onclick="return wnZoneAction('ns_subscription_add');" title="POST /dns/v2/zone/{zone}/nameserver-subscription">Set NS subscription</button>
            <button type="button" class="btn btn-default btn-sm" onclick="return wnZoneAction('partner_add');" title="POST /dns/v2/subscription/partner/zone/{zone}">Add to partner sub</button>
            <button type="button" class="btn btn-warning btn-sm" onclick="return wnZoneAction('partner_remove');" title="DELETE /dns/v2/subscription/partner/zone/{zone}">Remove from partner sub</button>
        </div>
    </div>

    <div class="sectionhead_ext open" style="margin-top:10px;">Existing Premium Subscriptions</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>GET /dns/v2/subscription/standalone-domains</code>. Renew/auto-renew/unsubscribe use the term selector above.</p>
        {if $wn_subscriptions}
        <table class="table table-condensed">
            <thead><tr><th class="fs11">Domain</th><th class="fs11">Sub ID</th><th class="fs11">Product</th><th class="fs11">Term</th><th class="fs11">Expiry</th><th class="fs11">Auto</th><th class="fs11">Actions</th></tr></thead>
            <tbody>
            {foreach from=$wn_subscriptions item=sub}
                <tr>
                    <td>{$sub.domainName|escape}</td>
                    <td>{$sub.subscriptionId|escape}</td>
                    <td>{$sub.subscriptionProduct|escape}</td>
                    <td>{$sub.subscriptionTerm}</td>
                    <td>{$sub.subscriptionExpiry|escape}</td>
                    <td>{if $sub.subscriptionAutoRenew}yes{else}no{/if}</td>
                    <td>
                        <button type="button" class="btn btn-primary btn-xs" onclick="return wnSubAction('sub_renew','{$sub.subscriptionId}');">Renew</button>
                        <button type="button" class="btn btn-default btn-xs" onclick="return wnSubAction('sub_autorenew_enable','{$sub.subscriptionId}');">Auto on</button>
                        <button type="button" class="btn btn-default btn-xs" onclick="return wnSubAction('sub_autorenew_disable','{$sub.subscriptionId}');">Auto off</button>
                        <button type="button" class="btn btn-danger btn-xs" onclick="return wnSubAction('sub_unsubscribe','{$sub.subscriptionId}');">Unsubscribe</button>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        {else}
            <p class="fs11"><i>No premium subscriptions (or credentials not yet valid).</i></p>
        {/if}
    </div>

    <div class="sectionhead_ext open" style="margin-top:10px;">Zone Forwarding (URL / Email)</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>GET/POST/DELETE /dns/v2/zone/{zone}/url-forwarding(s)</code> and <code>.../email-forwarding(s)</code>.</p>
        <div><b class="fs11">Zone (domain)</b><br/><input type="text" id="wn_fwd_zone" class="inp" style="width:250px;"/></div>
        <div style="margin-top:6px;"><input type="checkbox" id="wn_fwd_override"/> <span class="fs11">override conflicting record</span></div>
        <div style="margin-top:6px;">
            <b class="fs11">URL forwarding:</b>
            <input type="text" id="wn_fwd_subdomain" placeholder="subdomain" class="inp" style="width:120px;"/>
            <input type="text" id="wn_fwd_url" placeholder="https://target" class="inp" style="width:200px;"/>
            <button type="button" class="btn btn-success btn-xs" onclick="return wnFwdAction('url_fwd_add');">Add</button>
            <button type="button" class="btn btn-danger btn-xs" onclick="return wnFwdAction('url_fwd_remove');">Remove</button>
        </div>
        <div style="margin-top:6px;">
            <b class="fs11">Email forwarding:</b>
            <input type="text" id="wn_fwd_user" placeholder="user" class="inp" style="width:120px;"/>
            <input type="text" id="wn_fwd_email" placeholder="target@dest" class="inp" style="width:200px;"/>
            <button type="button" class="btn btn-success btn-xs" onclick="return wnFwdAction('email_fwd_add');">Add</button>
            <button type="button" class="btn btn-danger btn-xs" onclick="return wnFwdAction('email_fwd_remove');">Remove</button>
        </div>
    </div>
</div>
