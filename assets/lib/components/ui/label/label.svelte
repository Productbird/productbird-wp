<script lang="ts">
  import { Label as LabelPrimitive } from "bits-ui";
  import { cn } from "$lib/utils.js";
  import { labelClass } from ".";

  let { ref = $bindable(null), class: className, ...restProps }: LabelPrimitive.RootProps = $props();
</script>

<LabelPrimitive.Root bind:ref class={cn(labelClass, className)} {...restProps} />
