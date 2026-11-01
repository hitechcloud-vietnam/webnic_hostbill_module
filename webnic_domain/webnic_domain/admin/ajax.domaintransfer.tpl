{if $transfertype}
    <div class="alert alert-info" style="margin-bottom:8px;">
        Transfer type: <strong>{$transfertype}</strong>
        {if $transfertype == 'domain_owner'} (domain is already in this account){/if}
    </div>
{/if}
{if $transferinfo}
<table class="table table-hover">
    <tbody>
        <tr>
            <th>Status</th>
            <th>Request date</th>
            <th>Type</th>
            <th>Old registrar</th>
            <th>New registrar</th>
        </tr>
        <tr>
            <td><label class="label label-info">{$transferinfo.status}</label></td>
            <td>{$transferinfo.reDate}</td>
            <td>{$transferinfo.type}</td>
            <td>{$transferinfo.acID}</td>
            <td>{$transferinfo.reID}</td>
        </tr>
    </tbody>
</table>
{else}
    <div class="alert alert-warning">No transfer information available.</div>
{/if}
