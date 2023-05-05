<div class="d-flex left-part">
    <div class="navbar-header d-flex align-items-center">
        <a class="sidebar_toggle mb-1">
            <i class="bi bi-list fs24"></i>
        </a>
        <a class="navbar-brand" href="/">SDS Admin{if isset($_title) && !empty($_title)}: {$_title}{/if}</a>
    </div>

    {assign var="_user" value=$_acl->getCurrentUser()}

    {if !$_user->isGuest()}
        <ul class="nav navbar-nav topPanel_statistics">
            <li class="dropdown">
                <a class="dropdown-toggle toggle-link d-flex align-items-center statistic-link"
                   data-bs-toggle="dropdown"
                   role="button"
                   aria-haspopup="true"
                   aria-expanded="false">
                <span class="p-3">Статистики
                    <svg xmlns="http://www.w3.org/2000/svg"
                         width="8"
                         height="8"
                         fill="currentColor"
                         class="bi bi-caret-down-fill"
                         viewBox="0 0 16 16">
                        <path d="M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z"/>
                    </svg>
                </span>
                </a>
                <ul class="dropdown-menu dropdown-statistic">
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/webMonitor/monitor/webMonitor.monitor')
                        }">Monitor
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/page.stats.ilook_statistics')
                        }">Статистика по источникам
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/webMonitor/monitor/webMonitor.monitor.byMedia')}">
                            Статистика
                            по медиа-типам
                        </a>
                    </li>
                    <li role="separator" class="dropdown-divider"></li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/stats.grabberDashboard')
                        }">Grabber
                            dashboard
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/stats.templatesErrors')
                        }">Ошибки
                            шаблонов
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/stats.grabberHarvest')}">Сбор
                            граббера
                        </a>
                    </li>
                    <li role="separator" class="dropdown-divider"></li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/page.stats.bots')}">Статистика
                            по
                            ботам
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/page.stats.lang')}">Статистика
                            по
                            языкам
                        </a>
                    </li>
                    <li role="separator" class="dropdown-divider"></li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/page.stats.xauthorFields')
                        }">Статистика по полям авторов
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a class="text-nowrap" href="{$_application->getUrl('/sds/page/page.stats.xauthorFieldsCount')
                        }">Статистика по заполенности
                            авторов
                        </a>
                    </li>
                    <li role="separator" class="dropdown-divider"></li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/api/saccount/saccount.accountsCount')
                        }">sAccount: количество аккаунтов
                        </a>
                    </li>
                    <li role="separator" class="dropdown-divider"></li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/stats.collectionLag')
                        }">Отставание
                            сбора
                        </a>
                    </li>
                    <li class="dropdown-item">
                        <a href="{$_application->getUrl('/sds/page/page.stats.keywordStats')
                        }">Загруженность конфигов
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    {/if}
</div>

{* Переделать доступ, когда будет создана группа пользователей для кореференции *}
{if $_user->isAdmin()}
    <ul class="nav navbar-nav d-flex">
        <li class="dropdown">
            <a class="dropdown-toggle toggle-link"
               data-bs-toggle="dropdown"
               role="button"
               aria-hashpopup="true"
               aria-expanded="false">Кореференция <span class="bi bi-caret-down-fill"></span></a>
            <ul class="dropdown-menu">
                <li class="dropdown-item">
                    <a href="{$_application->getUrl('/plApi/synsets/synsetsList.page')}">Список синсетов</a>
                </li>
                <li class="dropdown-item">
                    <a href="{$_application->getUrl('/plApi/synsets/synsetCreate.page')}">Добавить синсет</a>
                </li>
            </ul>
        </li>
    </ul>
{/if}

<ul class="nav navbar-top-links sds-topPanel-userPanel ms-auto">
    <li class="dropdown m-0 me-md-3 me-lg-0 w-100">
        <a class="dropdown-toggle toggle-link d-block" data-bs-toggle="dropdown" href="#">
            <img class="sds-topPanel-avatar rounded-circle p-1" src="{$_user->getAvatarLink(42)}">
            <span class="pe-lg-3">{$_user->getLastName()}</span>
        </a>
        <ul class="dropdown-menu dropdown-user">
            {if !$_user->isGuest()}
                <li class="dropdown-item">
                    <a href="{$_application->getUrl('/authex/user/profile')}">{$i18n->get('Профиль')}</a>
                </li>
                <li class="dropdown-divider"></li>
                <li class="sds-topPanel-exit dropdown-item">
                    <a href="{$_application->getUrl('/authex/user/logout', ['redirectUrl' => {$_application->getUrl($_route->getName())|base64_encode}])}">{$i18n->get('Выйти')}</a>
                </li>
            {else}
                <li class="dropdown-item">
                    <a href="{$_application->getUrl('/authex/user/login')}">
                        <i class="fa fa-sign-in fa-fw"></i>
                        Login
                    </a>
                </li>
            {/if}
        </ul>
    </li>
</ul>