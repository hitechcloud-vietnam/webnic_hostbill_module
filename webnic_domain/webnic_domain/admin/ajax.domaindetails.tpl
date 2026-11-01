{if $info}
    Current status:<br>
    {foreach from=$info.domainstatus item=s}
        <label class="label label-info">{$s}</label><br>
    {/foreach}
{else}
    <div class="alert alert-warning">No domain information available.</div>
{/if}
