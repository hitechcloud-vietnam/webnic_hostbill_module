{if $info}
    Current status:<br>
    {foreach from=$info.domainstatus item=s}
        <label class="label label-info">{$s}</label>
        {if $s === 'pendingTransfer'}
            <a href="#" onclick="return loadWBCTransfer('{$domainid}')" class="btn btn-danger btn-sm">Transfer info</a>
        {/if}
    {/foreach}
    <hr>
    New status:<br>
    <div class="DomainStatusForm"
         data-action="?cmd=webnic_domain&action=domainstatus&id={$domainid}&update=true"
         data-method="POST">
        <div class="form-check">
            <input type="checkbox" class="form-check-input DomainStatus" data-status="clientDeleteProhibited" name="DomainStatus" value="clientDeleteProhibited" {if in_array('clientDeleteProhibited', $info.domainstatus)} checked="checked"{/if}>
            <label class="form-check-label">clientDeleteProhibited</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input DomainStatus" data-status="clientHold" name="DomainStatus" value="clientHold" {if in_array('clientHold', $info.domainstatus)} checked="checked"{/if}>
            <label class="form-check-label">clientHold</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input DomainStatus" data-status="clientRenewProhibited" name="DomainStatus" value="clientRenewProhibited" {if in_array('clientRenewProhibited', $info.domainstatus)} checked="checked"{/if}>
            <label class="form-check-label">clientRenewProhibited</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input DomainStatus" data-status="clientTransferProhibited" name="DomainStatus" value="clientTransferProhibited" {if in_array('clientTransferProhibited', $info.domainstatus)} checked="checked"{/if}>
            <label class="form-check-label">clientTransferProhibited</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input DomainStatus" data-status="clientUpdateProhibited" name="DomainStatus" value="clientUpdateProhibited" {if in_array('clientUpdateProhibited', $info.domainstatus)} checked="checked"{/if}>
            <label class="form-check-label">clientUpdateProhibited</label>
        </div>
        <button onclick="return submitDomainStatus();" type="button" class="btn btn-sm btn-primary">Save</button>
    </div>
{else}
    <div class="alert alert-warning">No domain information available.</div>
{/if}

{literal}
<script>
function submitDomainStatus() {
    var $DomainStatusForm = $(".DomainStatusForm");
    var $form = $("<form></form>");
    $form.attr('action', $DomainStatusForm.attr('data-action'));
    $form.attr('method', $DomainStatusForm.attr('data-method'));
    $form.append('{/literal}{securitytoken}{literal}');
    $($DomainStatusForm.find('.DomainStatus')).each(function() {
        if (this.checked) {
            $form.append('<input type="hidden" name="status[]" value="' + this.value + '">');
        }
    });
    $('body').append($form);
    $form.submit();
}
</script>
{/literal}
