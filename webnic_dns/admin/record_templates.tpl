{* webnic_dns admin: subscription record templates (full CRUD) *}
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
function wnTplCreate() {
    wnPost({ webnic_action: 'template_create',
        wn_template_name: document.getElementById('wn_tpl_name').value,
        wn_default_template: document.getElementById('wn_tpl_default').checked ? 1 : 0 });
    return false;
}
function wnTplDelete(id) {
    if (!confirm('Delete template #' + id + '?')) return false;
    wnPost({ webnic_action: 'template_delete', wn_template_id: id });
    return false;
}
function wnTplAddRec(id) {
    wnPost({ webnic_action: 'template_add_record', wn_template_id: id,
        wn_rec_type: document.getElementById('wn_rt_type_' + id).value,
        wn_rec_name: document.getElementById('wn_rt_name_' + id).value,
        wn_rec_ttl: document.getElementById('wn_rt_ttl_' + id).value,
        wn_rec_value: document.getElementById('wn_rt_val_' + id).value });
    return false;
}
function wnTplRemRec(id) {
    wnPost({ webnic_action: 'template_remove_record', wn_template_id: id,
        wn_rec_type: document.getElementById('wn_rt_type_' + id).value,
        wn_rec_name: document.getElementById('wn_rt_name_' + id).value,
        wn_rec_value: document.getElementById('wn_rt_val_' + id).value });
    return false;
}
</script>
{/literal}
<div style="padding:10px;">
    <div class="sectionhead_ext open">Create Record Template</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>POST /dns/v2/zone/subscription/template</code></p>
        <div><b class="fs11">Template name</b><br/><input type="text" id="wn_tpl_name" class="inp" style="width:250px;"/></div>
        <div style="margin-top:4px;"><input type="checkbox" id="wn_tpl_default"/> <span class="fs11">Default template (applied to new zones; overrides their records)</span></div>
        <div style="margin-top:8px;"><button type="button" class="btn btn-success btn-sm" onclick="return wnTplCreate();">Create template</button></div>
    </div>

    <div class="sectionhead_ext open" style="margin-top:10px;">Existing Templates</div>
    <div class="sectionbody" style="padding:10px;">
        <p class="fs11"><code>GET /dns/v2/zone/subscription/templates</code>, add/remove via <code>/{id}/add</code> &amp; <code>/{id}/remove</code>, delete via <code>DELETE /{id}</code>.</p>
        {foreach from=$wn_templates item=tpl}
        <div style="border:1px solid #ddd; padding:8px; margin-bottom:8px;">
            <b class="fs11">#{$tpl.id} {$tpl.name|escape}</b> {if $tpl.defaultTemplate}<span class="label label-info">default</span>{/if}
            <button type="button" class="btn btn-danger btn-xs" style="float:right;" onclick="return wnTplDelete('{$tpl.id}');">Delete</button>
            <table class="table table-condensed" style="margin-top:6px;">
                <thead><tr><th class="fs11">Type</th><th class="fs11">Name</th><th class="fs11">TTL</th><th class="fs11">Value(s)</th></tr></thead>
                <tbody>
                {foreach from=$tpl.records item=rec}
                    <tr><td>{$rec.type|escape}</td><td>{$rec.name|escape}</td><td>{$rec.ttl}</td>
                        <td>{foreach from=$rec.rdatas item=rd}{$rd.value|escape}<br/>{/foreach}</td></tr>
                {foreachelse}
                    <tr><td colspan="4"><i>no records</i></td></tr>
                {/foreach}
                </tbody>
            </table>
            <div class="fs11" style="margin-top:4px;">
                <input type="text" id="wn_rt_type_{$tpl.id}" placeholder="type" style="width:60px;" class="inp"/>
                <input type="text" id="wn_rt_name_{$tpl.id}" placeholder="name" style="width:90px;" class="inp"/>
                <input type="text" id="wn_rt_ttl_{$tpl.id}" placeholder="ttl" value="3600" style="width:60px;" class="inp"/>
                <input type="text" id="wn_rt_val_{$tpl.id}" placeholder="value" style="width:160px;" class="inp"/>
                <button type="button" class="btn btn-primary btn-xs" onclick="return wnTplAddRec('{$tpl.id}');">Add</button>
                <button type="button" class="btn btn-warning btn-xs" onclick="return wnTplRemRec('{$tpl.id}');">Remove</button>
            </div>
        </div>
        {foreachelse}
            <p class="fs11"><i>No record templates (or credentials not yet valid).</i></p>
        {/foreach}
    </div>
</div>
