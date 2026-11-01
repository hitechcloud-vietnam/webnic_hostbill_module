{* webnic_dns admin: whitelabel + default nameservers (partner-wide) *}
{literal}
<script type="text/javascript">
if (typeof window.wnPost !== 'function') {
    window.wnPost = function (fields) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = window.location.href;
        for (var k in fields) {
            if (!fields.hasOwnProperty(k)) continue;
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = k; i.value = fields[k];
            f.appendChild(i);
        }
        document.body.appendChild(f);
        f.submit();
    };
}
function wnWhitelabelSave() {
    wnPost({
        webnic_action: 'whitelabel_save',
        wn_nameservers: document.getElementById('wn_wl_ns').value,
        wn_admin_email: document.getElementById('wn_wl_email').value
    });
    return false;
}
function wnWhitelabelRemove() {
    if (!confirm('Remove the partner whitelabel nameservers?')) return false;
    wnPost({ webnic_action: 'whitelabel_remove' });
    return false;
}
</script>
{/literal}
<div style="padding:10px;">
    <div class="sectionhead_ext open">Whitelabel Nameservers</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11">WebNIC DNS v2: <code>GET/POST/DELETE /dns/v2/subscription/whitelabel/ns</code>. Partner-wide (applies to all premium zones).</p>
        {if $wn_whitelabel}
            <p class="fs11"><b>Current admin email:</b> {$wn_whitelabel.adminEmail|escape}</p>
            <p class="fs11"><b>Current nameservers:</b><br/>
            {foreach from=$wn_whitelabel.nameservers item=ns}{$ns|escape}<br/>{foreachelse}<i>none configured</i>{/foreach}</p>
        {else}
            <p class="fs11"><i>No whitelabel nameservers configured (or credentials not yet valid).</i></p>
        {/if}
        <div><b class="fs11">Nameservers</b> (one per line or comma separated)<br/>
            <textarea id="wn_wl_ns" rows="4" style="width:255px;" class="inp">{if $wn_whitelabel}{foreach from=$wn_whitelabel.nameservers item=ns}{$ns|escape}
{/foreach}{/if}</textarea>
        </div>
        <div><b class="fs11">Admin email</b><br/>
            <input type="text" id="wn_wl_email" style="width:250px;" class="inp" value="{if $wn_whitelabel}{$wn_whitelabel.adminEmail|escape}{/if}"/>
        </div>
        <div style="margin-top:8px;">
            <button type="button" class="btn btn-success btn-sm" onclick="return wnWhitelabelSave();">Save whitelabel NS</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="return wnWhitelabelRemove();">Remove whitelabel NS</button>
        </div>
    </div>

    <div class="sectionhead_ext open" style="margin-top:10px;">Default Nameservers (read-only)</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>GET /dns/v2/zone/basic/record/nameservers</code></p>
        <p class="fs11"><b>Basic:</b> {foreach from=$wn_basic_ns item=ns}{$ns|escape} {foreachelse}<i>n/a</i>{/foreach}</p>
        <p class="fs11"><code>GET /dns/v2/zone/subscription/record/nameservers</code></p>
        <p class="fs11"><b>Subscription:</b> {foreach from=$wn_sub_ns item=ns}{$ns|escape} {foreachelse}<i>n/a</i>{/foreach}</p>
    </div>
</div>
