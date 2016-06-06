{if !isset($item)}
    {assign var="item" value=null}
{/if}

{if isset($isMultiple) && $isMultiple}
    {assign var="isMultiple" value=true}
{else}
    {assign var="isMultiple" value=false}
{/if}
<link rel="stylesheet" href="/bower_components/chosen/chosen.min.css">
<script src="/bower_components/chosen/chosen.jquery.min.js" type="text/javascript"></script>
{if !isset($chosenSelect)}
    {assign var="chosenSelect" value=true}
<script type="text/javascript">
    {literal}
    $(document).ready(function () {
        $('.chosen-select').chosen({allow_single_deselect:true, width: '100%'});
    });
    {/literal}
</script>
{/if}

{assign var="options" value=[]}
{if $item === null}
    {assign var="fieldValue" value=[]}
{else}
    {assign var="fieldValue" value=$item->getFieldValue($fieldName)}
{/if}
{assign var="remainValues" value=count($fieldValue)}

{foreach from=$_entity->getMapper()->getAllRelatedModels($fieldName, $item) item='optionItem'}
    {assign var="itemValue" value=$optionItem->getFieldValue($_entity->getMapper()->getRelationField($fieldName))}
    {if $item === null}
        {assign var="selected" value=false}
    {else}
        {if $isMultiple}
            {assign var="itemFound" value=in_array($itemValue, $fieldValue, true)}
            {if $itemFound !== false}
                {assign var="selected" value=true}
                {assign var="remainValues" value=$remainValues-1}
            {else}
                {assign var="selected" value=false}
            {/if}
        {else}
            {assign var="selected" value=($fieldValue == $itemValue)}
        {/if}
    {/if}

    {append var="options" value=['title' => $optionItem->getTitleValue(), 'selected' => $selected, 'value' => $itemValue|htmlspecialchars]}
{foreachelse}
    {if isset($item) && !$isMultiple}
        {append var="options" value=['title' => $fieldValue, 'selected' => $selected, 'value' => $itemValue|htmlspecialchars]}
    {/if}
{/foreach}

<select data-placeholder="Make your choice..."
        {if $isMultiple}multiple{/if}
        id="item-{$fieldName}"
        name="item[{$fieldName}]{if $isMultiple}[]{/if}"
        class="form-control chosen-select"
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
        {if ($isMultiple && $remainValues > 0 || isset($readonly) && $readonly)} disabled{/if}
        >
    {if !$isMultiple}<option value="">Выбрать значение...</option>{/if}

    {if $isMultiple && $remainValues > 0}
        {foreach from=$fieldValue item="groupId"}
            <option value="{$groupId}" selected="selected">
                {$groupId}
            </option>
        {/foreach}
    {else}
        {foreach from=$options item="option"}
            <option value="{$option['value']}" {if $option['selected']} selected="selected"{/if}>
                {$option['title']}
            </option>
        {/foreach}
    {/if}
</select>
