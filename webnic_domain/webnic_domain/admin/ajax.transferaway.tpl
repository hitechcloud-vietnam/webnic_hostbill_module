{* webnic_domain: transfer-away status (registrar + reseller) with approve/reject *}
<div class="webnic-transferaway">
    <h6>Registrar transfer-away</h6>
    {if $registrar}
        <table class="table table-sm table-bordered">
            <tr><th>Record ID</th><td>{$registrar.id}</td></tr>
            <tr><th>Status</th><td><label class="label label-info">{$registrar.status}</label></td></tr>
            <tr><th>Remark</th><td>{$registrar.remark}</td></tr>
            <tr><th>Created</th><td>{$registrar.dtcreate}</td></tr>
            <tr><th>Registrant approved</th><td>{if $registrar.registrantApproved}Yes{else}No{/if}</td></tr>
            <tr><th>Reseller approved</th><td>{if $registrar.resellerApproved}Yes{else}No{/if}</td></tr>
        </table>
        <form id="ta-form-registrar-{$registrar.id}" class="form-inline" onsubmit="return false;">
            <select name="approveType" class="form-control input-sm">
                <option value="registrant">registrant</option>
                <option value="partner">partner</option>
            </select>
            <select name="approve" class="form-control input-sm">
                <option value="1">Approve</option>
                <option value="0">Reject</option>
            </select>
            <input type="text" name="approveRemarks" class="form-control input-sm" placeholder="Remarks (optional)"/>
            <button class="btn btn-primary btn-sm" onclick="return loadWBCTransferAwayUpdate('{$domainid}', 'registrar', '{$registrar.id}');">Submit</button>
        </form>
    {else}
        <div class="alert alert-warning">No registrar transfer-away request found.</div>
    {/if}

    <h6 style="margin-top:14px;">Reseller transfer-away</h6>
    {if $reseller}
        <table class="table table-sm table-bordered">
            <tr><th>Record ID</th><td>{$reseller.id}</td></tr>
            <tr><th>Status</th><td><label class="label label-info">{$reseller.status}</label></td></tr>
            <tr><th>Remark</th><td>{$reseller.remark}</td></tr>
            <tr><th>Created</th><td>{$reseller.dtcreate}</td></tr>
            <tr><th>Gaining reseller</th><td>{if $reseller.gainingReseller}Yes{else}No{/if}</td></tr>
            <tr><th>Losing reseller</th><td>{if $reseller.losingReseller}Yes{else}No{/if}</td></tr>
        </table>
        <form id="ta-form-reseller-{$reseller.id}" class="form-inline" onsubmit="return false;">
            <select name="approveType" class="form-control input-sm">
                <option value="registrant">registrant</option>
                <option value="partner">partner</option>
                <option value="both">both</option>
            </select>
            <select name="approve" class="form-control input-sm">
                <option value="1">Approve</option>
                <option value="0">Reject</option>
            </select>
            <input type="text" name="approveRemarks" class="form-control input-sm" placeholder="Remarks (optional)"/>
            <button class="btn btn-primary btn-sm" onclick="return loadWBCTransferAwayUpdate('{$domainid}', 'reseller', '{$reseller.id}');">Submit</button>
        </form>
    {else}
        <div class="alert alert-warning">No reseller transfer-away request found.</div>
    {/if}
</div>
