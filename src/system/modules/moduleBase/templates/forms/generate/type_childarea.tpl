<input
        type="text"
        class="form-control"
        id="item-{$fieldName}"
        name="item[{$fieldName}]"
        value="{if isset($item)}{$item->getFieldValue($fieldName)|htmlspecialchars}{/if}"
        {if isset($readonly) && $readonly} readonly{/if}
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
        >
{include file="./type_geojson.tpl" isMultiple=true}