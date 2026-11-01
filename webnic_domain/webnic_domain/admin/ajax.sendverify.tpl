{if $recipient}
    <div class="alert alert-success">
        <strong>Success!</strong> Verification email sent to: <code>{$recipient}</code>
    </div>
{else}
    <div class="alert alert-danger">
        <strong>Failed!</strong> Could not send the verification email. Please try again.
    </div>
{/if}
