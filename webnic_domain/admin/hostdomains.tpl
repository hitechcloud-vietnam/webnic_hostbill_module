{* webnic_domain: standalone host-linked domain list admin tool *}
<link href="{$moduleurl}styles.css" rel="stylesheet" media="all" />

<div id="newshelfnav" class="newhorizontalnav">
    <div class="list-1">
        <ul>
            <li><a href="?cmd={$modulename}"><span>Status</span></a></li>
            <li><a href="?cmd={$modulename}&action=registrant"><span>Registrant Accounts</span></a></li>
            <li class="active"><a href="?cmd={$modulename}&action=hostdomains"><span>Host Domains</span></a></li>
            <li><a href="?cmd={$modulename}&action=bulk"><span>Bulk</span></a></li>
        </ul>
    </div>
</div>

<div class="webnic-domain-panel" style="padding:15px 10px;">
    <h4>Host-linked Domain List</h4>
    <p>List domains bound to a given nameserver/host.</p>

    <form method="get" action="?cmd={$modulename}">
        <input type="hidden" name="cmd" value="{$modulename}"/>
        <input type="hidden" name="action" value="hostdomains"/>
        <div class="form-inline">
            <input type="text" name="nameserver" class="form-control" placeholder="e.g. ns1.nameserver.cc" value="{$nameserver}"/>
            <input type="number" name="page" class="form-control" value="1" style="width:90px;"/>
            <input type="number" name="pageSize" class="form-control" value="50" style="width:90px;"/>
            <button class="btn btn-primary btn-sm">Search</button>
        </div>
    </form>

    {if isset($items)}
        <table class="table table-sm table-bordered table-hover" style="margin-top:10px;">
            <thead class="thead-light">
                <tr><th>Domain</th><th>Registrant email</th><th>Nameservers</th><th>Status</th></tr>
            </thead>
            <tbody>
                {foreach from=$items item=d}
                    <tr>
                        <td>{$d.domainName}</td>
                        <td>{$d.registrantEmail}</td>
                        <td>{if $d.nameserver}{', '|implode:$d.nameserver}{/if}</td>
                        <td>{$d.status}</td>
                    </tr>
                {foreachelse}
                    <tr><td colspan="4">No domains found for this host.</td></tr>
                {/foreach}
            </tbody>
        </table>
    {/if}
</div>
<div class="clear" style="height: 20px;"></div>
