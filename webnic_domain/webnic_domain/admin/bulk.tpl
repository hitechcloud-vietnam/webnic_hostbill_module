{* webnic_domain: standalone bulk operations admin tool *}
<link href="{$moduleurl}styles.css" rel="stylesheet" media="all" />

<div id="newshelfnav" class="newhorizontalnav">
    <div class="list-1">
        <ul>
            <li><a href="?cmd={$modulename}"><span>Status</span></a></li>
            <li><a href="?cmd={$modulename}&action=registrant"><span>Registrant Accounts</span></a></li>
            <li><a href="?cmd={$modulename}&action=hostdomains"><span>Host Domains</span></a></li>
            <li class="active"><a href="?cmd={$modulename}&action=bulk"><span>Bulk</span></a></li>
        </ul>
    </div>
</div>

<div class="webnic-domain-panel" style="padding:15px 10px;">
    <h4>Bulk Operations</h4>

    {if isset($taskId)}
        <div class="alert {if $taskId}alert-success{else}alert-danger{/if}">
            {if $taskId}Batch submitted. Task ID: <code>{$taskId}</code>{else}Batch submission failed.{/if}
        </div>
    {/if}
    {if isset($jobOrderId)}
        <div class="alert {if $jobOrderId}alert-success{else}alert-danger{/if}">
            {if $jobOrderId}Contact replacement submitted. Job Order ID: <code>{$jobOrderId}</code>{else}Contact replacement failed.{/if}
        </div>
    {/if}

    <div class="row">
        <div class="col-md-6">
            <h6>Bulk register (JSON domainRegistrationList)</h6>
            <form method="post" action="?cmd={$modulename}&action=bulk">
                <input type="hidden" name="do" value="register"/>
                <textarea name="registrations" class="form-control" rows="5" placeholder='[{literal}{"domainName":"a.com","term":1,"nameservers":["ns1.x.com","ns2.x.com"],"registrantContactId":"...","administratorContactId":"...","technicalContactId":"...","billingContactId":"...","registrantUserId":"REG100165"}{/literal}]'></textarea>
                <button class="btn btn-primary btn-sm" style="margin-top:6px;">Submit bulk registration</button>
            </form>
        </div>
        <div class="col-md-6">
            <h6>Bulk renew (one per line: domain term)</h6>
            <form method="post" action="?cmd={$modulename}&action=bulk">
                <input type="hidden" name="do" value="renew"/>
                <textarea name="renewals" class="form-control" rows="5" placeholder="example.com 1&#10;another.com 2"></textarea>
                <button class="btn btn-primary btn-sm" style="margin-top:6px;">Submit bulk renewal</button>
            </form>
        </div>
    </div>

    <div class="row" style="margin-top:14px;">
        <div class="col-md-6">
            <h6>Bulk replace contact</h6>
            <form method="post" action="?cmd={$modulename}&action=bulk">
                <input type="hidden" name="do" value="replace"/>
                <textarea name="domains" class="form-control" rows="4" placeholder="one domain per line"></textarea>
                <input type="text" name="registrant" class="form-control input-sm" placeholder="Registrant contact ID"/>
                <input type="text" name="administrator" class="form-control input-sm" placeholder="Administrator contact ID"/>
                <input type="text" name="technical" class="form-control input-sm" placeholder="Technical contact ID"/>
                <input type="text" name="billing" class="form-control input-sm" placeholder="Billing contact ID"/>
                <button class="btn btn-warning btn-sm" style="margin-top:6px;">Replace contacts</button>
            </form>
        </div>
        <div class="col-md-6">
            <h6>Batch report lookup</h6>
            <form method="post" action="?cmd={$modulename}&action=bulk">
                <input type="hidden" name="do" value="report"/>
                <input type="text" name="taskId" class="form-control" placeholder="Task ID"/>
                <button class="btn btn-info btn-sm" style="margin-top:6px;">Get report</button>
            </form>
            {if isset($overview) && $overview}
                <table class="table table-sm table-bordered" style="margin-top:8px;">
                    <tr><th>Action</th><td>{$overview.action}</td></tr>
                    <tr><th>Status</th><td>{$overview.status}</td></tr>
                    <tr><th>Total</th><td>{$overview.total}</td></tr>
                </table>
            {/if}
            {if isset($details) && $details}
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Domain</th><th>Status</th></tr></thead>
                    <tbody>
                        {foreach from=$details item=row}
                            <tr><td>{$row.domainName}</td><td>{$row.status}</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}
        </div>
    </div>
</div>
<div class="clear" style="height: 20px;"></div>
