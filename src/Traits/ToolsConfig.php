<?php

namespace Productbird\Traits;

/**
 * Trait containing helper methods for working with tools.
 *
 * @since 0.1.0
 */
trait ToolsConfig
{
    public const MAX_BATCH_SIZE = 250;
    // Shared meta keys (used across all tools when needed)
    public const SHARED_META_KEY_GLOBAL_STATUS = '_productbird_global_status'; // Overall AI processing status
    public const SHARED_META_KEY_LAST_UPDATED = '_productbird_last_updated';   // When any AI tool last ran

    // Tool-specific meta key constants for Magic Descriptions
    public const MAGIC_DESCRIPTIONS_META_KEY_STATUS = '_productbird_magic_descriptions_status';
    public const MAGIC_DESCRIPTIONS_META_KEY_STATUS_ID = '_productbird_magic_descriptions_status_id';
    public const MAGIC_DESCRIPTIONS_META_KEY_ERROR = '_productbird_magic_descriptions_error';
    public const MAGIC_DESCRIPTIONS_META_KEY_DRAFT = '_productbird_magic_descriptions_draft';
    public const MAGIC_DESCRIPTIONS_META_KEY_DELIVERED = '_productbird_magic_descriptions_delivered';

    /**
     * Get available tools configuration.
     *
     * @return array
     */
    protected function get_tools(): array
    {
        return [
            'MAGIC_DESCRIPTIONS' => [
                'id' => 'magic-descriptions',
                'slug' => 'magic-descriptions',
                'name' => __('Magic Descriptions', 'productbird'),
                'description' => __('Generate product descriptions with AI', 'productbird'),
                'icon' => 'magic-wand',
                'meta_keys' => [
                    'generation_status' => self::MAGIC_DESCRIPTIONS_META_KEY_STATUS,
                    'status_id' => self::MAGIC_DESCRIPTIONS_META_KEY_STATUS_ID,
                    'error' => self::MAGIC_DESCRIPTIONS_META_KEY_ERROR,
                    'description_draft' => self::MAGIC_DESCRIPTIONS_META_KEY_DRAFT,
                    'delivered' => self::MAGIC_DESCRIPTIONS_META_KEY_DELIVERED,
                ],
                'endpoints' => [
                    'bulk' => [
                        'method' => 'POST',
                        'endpoint' => 'productbird/v1/magic-descriptions/bulk',
                        'callback_endpoint' => 'productbird/v1/webhooks',
                    ],
                ],
            ],
            /*
            'ALT_IMAGE_GENIE' => [
                'id' => 'alt-image-genie',
            ],
            */
        ];
    }

    /**
     * Get shared configuration that applies to all tools.
     *
     * @return array
     */
    protected function get_shared_config(): array
    {
        return [
            'max_batch_size' => self::MAX_BATCH_SIZE,
            'bulk_action_group_label' => 'productbird_ai_options',
            'shared_meta_keys' => [
                'global_status' => self::SHARED_META_KEY_GLOBAL_STATUS,
                'last_updated' => self::SHARED_META_KEY_LAST_UPDATED,
            ],
        ];
    }

    /**
     * Get the tool by name.
     *
     * @param string $name
     * @return array|null
     */
    protected function get_tool_config(string $name): ?array
    {
        $tool = $this->get_tools()[$name] ?? null;

        if ( ! $tool ) {
            throw new \Exception('Tool not found: ' . $name);
        }

        // Merge shared config with tool config
        return array_merge($this->get_shared_config(), $tool);
    }
}