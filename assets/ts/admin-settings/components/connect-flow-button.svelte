<script lang="ts">
  import ConnectButton from "$lib/components/connect-button.svelte";
  import * as Button from "$lib/components/ui/button/index.js";
  import { __ } from "@wordpress/i18n";
  import { adminSettings } from "$admin-settings/admin-state.svelte.js";

  // Destructure the OIDC data for convenience.
  const oidc = adminSettings?.oidc ?? {};

  // The disconnect URL comes from wp_nonce_url() which can have encoded entities
  // which need to be decoded before using in href
  function decodeUrl(url: string): string {
    // The PHP side could return URLs with HTML entities like &amp;
    // so we need to decode them
    return url ? decodeURIComponent(url.replace(/&amp;/g, "&")) : "";
  }

  let disconnectUrl = "";
  if (oidc.disconnect_url) {
    disconnectUrl = decodeUrl(oidc.disconnect_url);
  }
</script>

{#if oidc.is_connected}
  <div class="flex flex-col">
    <p class="mb-2">
      <strong>{__("Connected to Productbird", "productbird")}</strong>
    </p>

    {#if oidc.disconnect_url}
      <Button.Root href={disconnectUrl} size="sm" variant="destructive">
        {__("Disconnect", "productbird")}
      </Button.Root>
    {/if}
  </div>
{:else if oidc.auth_url}
  <!-- When not connected show the ConnectButton linking to the auth URL -->
  <ConnectButton href={decodeUrl(oidc.auth_url)} />
{:else}
  <p>{__("Unable to create connect URL. Please try again later.", "productbird")}</p>
{/if}
