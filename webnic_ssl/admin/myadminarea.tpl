{*
  WebNIC SSL — admin service management panel.
  Rendered by webnic_ssl_controller::accountdetails() as the service custom_template.
  Variables: $cert, $cert_status, $csr_data, $validation, $dcv_status, $serviceid.
*}
<ul class="accor white cert webnic-ssl">
    <li>
        <a href="#">WebNIC SSL Certificate</a>
        <div class="sor">
            <div class="row">
                <div class="col-md-3"><strong>Common name</strong></div>
                <div class="col-md-9">{$cert.cn|default:'N/A'|escape}</div>
            </div>
            <div class="row">
                <div class="col-md-3"><strong>Order ID</strong></div>
                <div class="col-md-9">{$cert.orderid|default:'N/A'|escape}</div>
            </div>
            <div class="row">
                <div class="col-md-3"><strong>Status</strong></div>
                <div class="col-md-9"><span class="label label-default">{$cert_status|default:'N/A'|escape}</span></div>
            </div>
            <div class="row">
                <div class="col-md-3"><strong>Product</strong></div>
                <div class="col-md-9">{$cert.productKey|default:'N/A'|escape}</div>
            </div>
            <div class="row">
                <div class="col-md-3"><strong>DCV Method</strong></div>
                <div class="col-md-9">{$cert.dcv|default:'N/A'|upper}</div>
            </div>
            {if $cert.san}
                <div class="row">
                    <div class="col-md-3"><strong>SAN</strong></div>
                    <div class="col-md-9">
                        {foreach from=$cert.san item=san}
                            <div>{$san|escape}</div>
                        {/foreach}
                    </div>
                </div>
            {/if}

            <div class="row">
                <div class="col-md-3"><strong>Actions</strong></div>
                <div class="col-md-9">
                    <a class="btn btn-default btn-sm" href="?cmd=webnic_ssl&action=sync&id={$serviceid}">Sync status</a>
                    <a class="btn btn-default btn-sm" href="?cmd=webnic_ssl&action=renew&id={$serviceid}">Renew</a>
                    <a class="btn btn-default btn-sm" href="?cmd=webnic_ssl&action=resend&id={$serviceid}">Resend approver email</a>
                    <a class="btn btn-success btn-sm" href="?cmd=webnic_ssl&action=download&id={$serviceid}">Download certificate</a>
                    <button type="button" id="wn-show-csr" class="btn btn-default btn-sm">Show / edit CSR</button>
                </div>
            </div>

            {* ---- Change DCV method ---- *}
            <hr />
            <div class="row">
                <div class="col-md-12">
                    <p><strong>Change validation (DCV) method</strong></p>
                    <form method="post" action="?cmd=webnic_ssl&action=changedcv&id={$serviceid}" class="form-inline">
                        <select name="method" class="form-control input-sm">
                            <option value="dns">DNS</option>
                            <option value="http">HTTP (file)</option>
                            <option value="email">Email</option>
                        </select>
                        <input type="text" name="email" class="form-control input-sm" placeholder="approver email (for email DCV)" />
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        {securitytoken}
                    </form>
                </div>
            </div>

            {* ---- Live DCV details while validating ---- *}
            {if $validation}
                {if $validation.method == 'dns' && $validation.dns}
                    <hr />
                    <div class="row"><div class="col-md-12">
                        <p><strong>DNS Verification</strong> — add the record(s) below to each zone:</p>
                        {foreach from=$validation.dns item=rec key=rname}
                            <p><strong>For {$rname|escape}:</strong></p>
                            <pre>{$rec.name|escape} 3600 IN {$rec.type|escape} {$rec.content|escape}</pre>
                        {/foreach}
                    </div></div>
                {/if}
                {if $validation.method == 'http' && $validation.file}
                    <hr />
                    <div class="row"><div class="col-md-12">
                        <p><strong>File Verification</strong> — publish each file at the URL shown with the given content:</p>
                        {foreach from=$validation.file item=f key=fname}
                            <p><strong>For {$fname|escape}:</strong></p>
                            <pre>{$f.url|escape}</pre>
                            <pre>{$f.data|escape}</pre>
                        {/foreach}
                    </div></div>
                {/if}
                {if $validation.method == 'email' && $validation.email}
                    <hr />
                    <div class="row"><div class="col-md-12">
                        <p><strong>Email Verification</strong> — approver address per domain:</p>
                        <ul>
                            {foreach from=$validation.email item=addr key=ename}
                                <li><b>{$ename|escape}</b>: <code>{$addr|escape}</code></li>
                            {/foreach}
                        </ul>
                    </div></div>
                {/if}
            {/if}
        </div>
    </li>
</ul>

<div id="wn-csr-dialog" hidden>
    <strong>Certificate Signing Request</strong>
    <pre style="margin:auto;">{$cert.csr|default:''|escape}</pre>
    <form method="post" action="?cmd=webnic_ssl&action=reissue&id={$serviceid}">
        <p>Paste a new CSR to reissue the certificate (leave unchanged to reissue with the stored CSR):</p>
        <textarea name="csr" class="form-control" style="min-width:100%;min-height:220px;">{$cert.csr|default:''|escape}</textarea>
        <p style="text-align:right;margin-top:6px;">
            <button type="submit" class="btn btn-warning btn-sm">Reissue</button>
        </p>
        {securitytoken}
    </form>
    {if $csr_data}
        <hr />
        <strong>Stored CSR details</strong>
        <table class="table table-striped">
            {foreach from=$csr_data item=val key=key}
                <tr><td>{$key|escape}</td><td>{if is_array($val)}{', '|implode:$val|escape}{else}{$val|escape}{/if}</td></tr>
            {/foreach}
        </table>
    {/if}
</div>

{literal}
<style>
    .webnic-ssl .sor .row { margin-bottom: 10px; }
</style>
<script>
    $(function () {
        $('#wn-show-csr').on('click', function () {
            bootbox.dialog({ message: $('#wn-csr-dialog').html() });
            return false;
        });
    });
</script>
{/literal}
