{block name=head}
    <link rel="stylesheet" href="/custom/sds/default_tpl/style.css">
{/block}

<script type="application/javascript" src="/custom/sds/default_tpl/searchFocus.js"></script>

<div class="row sds-spacer hidden-sm d-none d-md-block">
    <div class="col-lg-12">&nbsp;</div>
</div>

<div class="row" id="sds-blue-search-form">
    <div class="col-md-10 offset-md-1">
        <form action="/search" role="form" aria-label="Поиск в SDS">
            <div class="col-12">
                <div class="input-group d-flex align-items-center search-panel rounded-1 ps-1">
                    <div class="btn dsbld text-white w150">SDS</div>
                    <input class="form-control search-input" tabindex="2" type="text" maxlength="400" name="q" value="">
                    <button tabindex="-1" class="btn text-white rounded-end fw-normal" type="submit">Найти</button>
                </div>
            </div>
            <div class="row mt20">
                <div id="sds-blue-search-controller" class="d-flex col-12 flex-column align-items-center">
                    <div class="mb-2 d-flex flex-row flex-wrap align-items-center justify-content-center w-100">
                        {foreach from=$data.mediaTypeMapper->getAllCached() item="media"}
                            <label class="m-1">
                                <input class="form-check-input me-2" type="checkbox" name="qm[]"
                                       value="{$media->getMediaId()}">
                                {$media->getName()}
                            </label>
                            &nbsp;&nbsp;
                        {/foreach}
                        <label class="m-1">
                            <select title="Сортировка" name="qs" class="form-control form-select">
                                <option value="relevance">По релевантности</option>
                                <option value="created">По дате написания</option>
                                <option value="stored" selected>По дате сбора</option>
                            </select>
                        </label>
                        <div class="d-flex">
                            <input type="hidden" name="dateFrom" id="date-from">
                            <input type="hidden" name="dateTo" id="date-to">
                            <div class="d-flex align-items-center m-1">
                                <span>с</span>
                                <input type="date"
                                       data-type="from"
                                       class="datepicker-interval form-control date-input_from-to ms-2"
                                       onchange="setDateParams(this)">
                            </div>
                            <div class="d-flex align-items-center m-1">
                                <span>по</span>
                                <input type="date"
                                       data-type="to"
                                       class="datepicker-interval form-control date-input_from-to ms-2"
                                       onchange="setDateParams(this)">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript" src="/custom/sds/default_tpl/dateFromUrl.js"></script>