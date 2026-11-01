<div style="margin:10px 0px;">
    <ul class="accor">
        <li>
            <div id="domreq-action"></div>
        </li>
        <li>
            <a>Domain Actions</a>
            <div id="domreq-details">
                <a href="#" onclick="return loadWBCContacts('{$domainid}')" class="btn btn-primary btn-sm">View current contacts</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'SyncContact')" class="btn btn-primary btn-sm">Sync contacts</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'ChangeEpp')" class="btn btn-warning btn-sm">Reset EPP</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'Lock')" class="btn btn-primary btn-sm">Lock</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'Unlock')" class="btn btn-warning btn-sm">Unlock</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'Hold')" class="btn btn-danger btn-sm">Suspend</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'RemoveHold')" class="btn btn-primary btn-sm">Un-suspend</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'SendVerify')" class="btn btn-success btn-sm">Resend verification email</a>
                <a href="#" onclick="return loadWBCStatus('{$domainid}')" class="btn btn-info btn-sm">Status</a>
                <a href="#" onclick="return loadWBCTransfer('{$domainid}')" class="btn btn-info btn-sm">Transfer status</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'Certificate')" class="btn btn-info btn-sm">Download certificate&hellip;</a>
                <a href="#" onclick="return loadWBCAction('{$domainid}', 'TaxLookup')" class="btn btn-info btn-sm">Tax code lookup</a>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div id="domreq-status"></div>
                </div>
                <div class="col-md-6">
                    <div id="domreq-transfer"></div>
                </div>
            </div>
        </li>
    </ul>
</div>

{literal}
<script>
    function loadWBCStatus(id) {
        ajax_update('?cmd=webnic_domain&action=domainstatus&id=' + id, { load: 1 }, '#domreq-status', true);
        return false;
    }
    function loadWBCContacts(id) {
        ajax_update('?cmd=webnic_domain&action=domaincontacts&id=' + id, { load: 1 }, '#domreq-status', true);
        return false;
    }
    function loadWBCTransfer(id) {
        ajax_update('?cmd=webnic_domain&action=domaintransfer&id=' + id, { load: 1 }, '#domreq-transfer', true);
        return false;
    }
    function loadWBCAction(id, action) {
        ajax_update('?cmd=webnic_domain&action=domainaction&ac=' + action + '&id=' + id, { load: 1 }, '#domreq-status', true);
        return false;
    }
    function loadWBCCertificate(id, lang) {
        window.open('?cmd=webnic_domain&action=downloadcertificate&id=' + id + '&lang=' + lang, '_blank');
        return false;
    }
    function loadWBCTaxLookup(id, mst) {
        ajax_update('?cmd=webnic_domain&action=domainaction&ac=TaxLookup&id=' + id + '&mst=' + encodeURIComponent(mst), { load: 1 }, '#domreq-status', true);
        return false;
    }
</script>
{/literal}
