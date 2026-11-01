{* webnic_domain: inline certificate language picker + download *}
<form id="certificate-form-{$domainid}" class="form-inline" onsubmit="return false;">
    <div class="form-group">
        <label for="cert-lang-{$domainid}" style="margin-right:8px;">Certificate language:</label>
        <select id="cert-lang-{$domainid}" name="lang" class="form-control input-sm" style="margin-right:8px;">
            <option value="eng">English</option>
            <option value="chi">Chinese Simplified</option>
            <option value="zho">Chinese Traditional</option>
            <option value="ind">Indonesian</option>
            <option value="tha">Thai</option>
            <option value="vie">Vietnamese</option>
        </select>
    </div>
    <button type="button" class="btn btn-success btn-sm"
            onclick="return loadWBCCertificate('{$domainid}', $('#cert-lang-{$domainid}').val());">
        Download certificate
    </button>
</form>
