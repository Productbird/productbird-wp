<script lang="ts">
  import * as Dialog from "$lib/components/ui/dialog/index.js";

  let { selectedIds = [] }: { selectedIds: number[] } = $props();

  let open = $state(true);
</script>

<Dialog.Root bind:open>
  <Dialog.Content class="max-w-screen-lg min-h-0 max-h-[80vh] overflow-y-auto">
    <h1>Product Description Modal</h1>
    <p>Selected IDs: {selectedIds.join(", ")}</p>
  </Dialog.Content>
</Dialog.Root>

<style>
  :global(.woocommerce-layout__header) {
    z-index: 10;
  }
</style>
