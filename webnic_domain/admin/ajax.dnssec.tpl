{* webnic_domain: DNSSEC management panel *}
<div class="webnic-dnssec">
    {if !$supported}
        <div class="alert alert-warning">DNSSEC is not supported for this domain's registry.</div>
    {else}
        <div class="alert alert-info">DNSSEC is supported for this domain.</div>

        {if $dsDatas}
            <h6>Current DS records</h6>
            <table class="table table-sm table-bordered">
                <thead class="thead-light">
                    <tr><th>Key tag</th><th>Algorithm</th><th>Digest type</th><th>Digest</th></tr>
                </thead>
                <tbody>
                    {foreach from=$dsDatas item=ds}
                        <tr>
                            <td>{$ds.keyTag}</td>
                            <td>{$ds.algorithm}</td>
                            <td>{$ds.digestType}</td>
                            <td style="word-break:break-all;">{$ds.digest}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <p>No DS records are currently set.</p>
        {/if}

        <h6>Set / replace DS records</h6>
        <form id="dnssec-form-{$domainid}" onsubmit="return false;">
            {section name=r loop=3}
                <div class="form-row" style="margin-bottom:6px;">
                    <input type="text" name="keyTag[]" class="form-control input-sm" placeholder="Key tag" style="width:15%;display:inline-block;"/>
                    <input type="text" name="algorithm[]" class="form-control input-sm" placeholder="Algorithm (e.g. 8,13)" style="width:20%;display:inline-block;"/>
                    <input type="text" name="digestType[]" class="form-control input-sm" placeholder="Digest type (1-4)" style="width:20%;display:inline-block;"/>
                    <input type="text" name="digest[]" class="form-control input-sm" placeholder="Digest (hex)" style="width:40%;display:inline-block;"/>
                </div>
            {/section}
            <button class="btn btn-primary btn-sm" onclick="return loadWBCDnssecUpdate('{$domainid}');">Save DS records</button>
            <button class="btn btn-danger btn-sm" onclick="return loadWBCDnssecDelete('{$domainid}');">Disable DNSSEC</button>
        </form>
    {/if}
</div>
