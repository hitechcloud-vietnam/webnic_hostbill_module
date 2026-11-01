{* webnic_domain: standalone certificate download form *}
<h4>Download Certificate</h4>
<p>Domain: <strong>{$domainname}</strong></p>

<form action="?cmd=webnic_domain&action=downloadcertificate" method="get" target="_blank">
    <input type="hidden" name="cmd"    value="webnic_domain"/>
    <input type="hidden" name="action" value="downloadcertificate"/>
    <input type="hidden" name="id"     value="{$domainid}"/>

    <div class="form-group">
        <label for="lang">Certificate language:</label>
        <select name="lang" id="lang" class="form-control">
            <option value="eng">English (default)</option>
            <option value="chi">Chinese Simplified</option>
            <option value="zho">Chinese Traditional</option>
            <option value="ind">Indonesian</option>
            <option value="tha">Thai</option>
            <option value="vie">Vietnamese</option>
        </select>
    </div>

    <button type="submit" class="btn btn-primary">Download certificate (.pdf)</button>
</form>
