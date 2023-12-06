<form action="{$_slim->urlFor($_route->getName())}" method="get" role="form" class="js--form_search form-search">
    <div class="input-group mb-3">
        <input name="q" id="js--list_search" type="text" class="form-control form-control-sm rounded-start" placeholder="Поиск" value="{$data.query|htmlentities}">
        <button class="btn btn-outline-secondary rounded-end" type="submit" id="button-addon2">
          <i class="bi bi-search" aria-hidden="true"></i>
        </button>
    </div>
</form>