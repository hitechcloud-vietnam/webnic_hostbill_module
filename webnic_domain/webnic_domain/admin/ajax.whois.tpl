{* webnic_domain: Universal WHOIS lookup *}
<div class="webnic-whois">
    {if $whois}
        <div class="alert alert-info" style="margin-bottom:8px;">
            Universal WHOIS is best-effort; RDDS/WHOIS is being phased out for many TLDs.
        </div>
        {if $whois.domain}
            <h6>Domain</h6>
            <table class="table table-sm table-bordered">
                <tr><th>Name</th><td>{$whois.domain.name}</td></tr>
                <tr><th>Status</th><td>{if $whois.domain.status}{', '|implode:$whois.domain.status}{/if}</td></tr>
                <tr><th>Nameservers</th><td>{if $whois.domain.nameservers}{', '|implode:$whois.domain.nameservers}{/if}</td></tr>
                <tr><th>Created</th><td>{$whois.domain.creationDate}</td></tr>
                <tr><th>Updated</th><td>{$whois.domain.updatedDate}</td></tr>
                <tr><th>Expires</th><td>{$whois.domain.expiryDate}</td></tr>
                <tr><th>DNSSEC</th><td>{$whois.domain.dnssec}</td></tr>
            </table>
        {/if}
        {if $whois.registrar}
            <h6>Registrar</h6>
            <table class="table table-sm table-bordered">
                <tr><th>Name</th><td>{$whois.registrar.name}</td></tr>
                <tr><th>IANA ID</th><td>{$whois.registrar.id}</td></tr>
                <tr><th>URL</th><td>{$whois.registrar.url}</td></tr>
            </table>
        {/if}
    {else}
        <div class="alert alert-warning">No WHOIS information available for this domain.</div>
    {/if}
</div>
