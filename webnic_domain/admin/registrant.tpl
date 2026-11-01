{* webnic_domain: standalone registrant-account admin tool *}
<link href="{$moduleurl}styles.css" rel="stylesheet" media="all" />

<div id="newshelfnav" class="newhorizontalnav">
    <div class="list-1">
        <ul>
            <li><a href="?cmd={$modulename}"><span>Status</span></a></li>
            <li class="active"><a href="?cmd={$modulename}&action=registrant"><span>Registrant Accounts</span></a></li>
            <li><a href="?cmd={$modulename}&action=hostdomains"><span>Host Domains</span></a></li>
            <li><a href="?cmd={$modulename}&action=bulk"><span>Bulk</span></a></li>
        </ul>
    </div>
</div>

<div class="webnic-domain-panel" style="padding:15px 10px;">
    <h4>Registrant Accounts</h4>

    {if isset($created)}
        {if $created}
            <div class="alert alert-success">
                Created registrant account <strong>{$created.registrantUserId}</strong> (username {$created.username}).
                Password: <code>{$created.password}</code>
            </div>
        {else}
            <div class="alert alert-danger">Failed to create registrant account.</div>
        {/if}
    {/if}
    {if isset($reassigned)}
        <div class="alert {if $reassigned}alert-success{else}alert-danger{/if}">
            {if $reassigned}Domain reassigned successfully.{else}Reassignment failed.{/if}
        </div>
    {/if}

    <div class="row">
        <div class="col-md-4">
            <h6>Create account</h6>
            <form method="post" action="?cmd={$modulename}&action=registrant">
                <input type="hidden" name="do" value="create"/>
                <div class="form-group">
                    <input type="text" name="username" class="form-control" placeholder="Username (min 10, mixed alnum)"/>
                </div>
                <button class="btn btn-primary btn-sm">Create</button>
            </form>
        </div>
        <div class="col-md-4">
            <h6>Search accounts</h6>
            <form method="post" action="?cmd={$modulename}&action=registrant">
                <input type="hidden" name="do" value="search"/>
                <div class="form-group">
                    <input type="text" name="keyword" class="form-control" placeholder="Keyword (min 5)" value="{$keyword}"/>
                </div>
                <button class="btn btn-primary btn-sm">Search</button>
            </form>
            {if isset($results)}
                <table class="table table-sm table-bordered" style="margin-top:8px;">
                    <thead><tr><th>ID</th><th>Username</th></tr></thead>
                    <tbody>
                        {foreach from=$results item=r}
                            <tr><td>{$r.id}</td><td>{$r.username}</td></tr>
                        {foreachelse}
                            <tr><td colspan="2">No matches.</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}
        </div>
        <div class="col-md-4">
            <h6>Reassign domain</h6>
            <form method="post" action="?cmd={$modulename}&action=registrant">
                <input type="hidden" name="do" value="reassign"/>
                <div class="form-group">
                    <input type="text" name="domainName" class="form-control" placeholder="Domain name"/>
                </div>
                <div class="form-group">
                    <input type="text" name="registrantUsername" class="form-control" placeholder="Target registrant username"/>
                </div>
                <button class="btn btn-warning btn-sm">Reassign</button>
            </form>
        </div>
    </div>
</div>
<div class="clear" style="height: 20px;"></div>
