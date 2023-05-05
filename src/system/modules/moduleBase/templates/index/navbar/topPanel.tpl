<div class="d-flex left-part align-items-center">
    <div class="navbar-header d-flex align-items-center">
        <a class="sidebar_toggle mb-1">
            <i class="bi bi-list fs24"></i>
        </a>
        <a class="navbar-brand" href="/">MPCMF Admin{if isset($_title) && !empty($_title)}: {$_title}{/if}</a>
    </div>
</div>

<ul class="nav navbar-top-links navbar-right">
    <li class="dropdown">
        <a class="dropdown-toggle" data-bs-toggle="dropdown" href="#" >
            <i class="bi bi-envelope-fill"></i> <i class="fa fa-caret-down"></i>
        </a>
        <ul class="dropdown-menu dropdown-messages top-30 end-0">
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <strong>John Smith</strong>
                        <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                    </div>
                    <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <strong>John Smith</strong>
                        <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                    </div>
                    <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <strong>John Smith</strong>
                        <span class="pull-right text-muted">
                                        <em>Yesterday</em>
                                    </span>
                    </div>
                    <div>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Pellentesque eleifend...</div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="text-center d-block" href="#">
                    <strong>Read All Messages</strong>
                    <i class="fa fa-angle-right"></i>
                </a>
            </li>
        </ul>
        <!-- /.dropdown-messages -->
    </li>
    <!-- /.dropdown -->
    <li class="dropdown">
        <a class="dropdown-toggle" data-bs-toggle="dropdown" href="#">
            <i class="bi bi-card-list"></i> <i class="fa fa-caret-down"></i>
        </a>
        <ul class="dropdown-menu dropdown-tasks top-30 end-0">
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <p>
                            <strong>Task 1</strong>
                            <span class="pull-right text-muted">40% Complete</span>
                        </p>
                        <div class="progress progress-striped active">
                            <div class="progress-bar bg-success" role="progressbar" aria-valuenow="40"
                                 aria-valuemin="0" aria-valuemax="100" style="width: 40%">
                                <span class="sr-only">40% Complete (success)</span>
                            </div>
                        </div>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <p>
                            <strong>Task 2</strong>
                            <span class="pull-right text-muted">20% Complete</span>
                        </p>
                        <div class="progress progress-striped active">
                            <div class="progress-bar bg-info" role="progressbar" aria-valuenow="20"
                                 aria-valuemin="0" aria-valuemax="100" style="width: 20%">
                                <span class="sr-only">20% Complete</span>
                            </div>
                        </div>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <p>
                            <strong>Task 3</strong>
                            <span class="pull-right text-muted">60% Complete</span>
                        </p>
                        <div class="progress progress-striped active">
                            <div class="progress-bar bg-warning" role="progressbar" aria-valuenow="60"
                                 aria-valuemin="0" aria-valuemax="100" style="width: 60%">
                                <span class="sr-only">60% Complete (warning)</span>
                            </div>
                        </div>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <p>
                            <strong>Task 4</strong>
                            <span class="pull-right text-muted">80% Complete</span>
                        </p>
                        <div class="progress progress-striped active">
                            <div class="progress-bar bg-danger" role="progressbar" aria-valuenow="80"
                                 aria-valuemin="0" aria-valuemax="100" style="width: 80%">
                                <span class="sr-only">80% Complete (danger)</span>
                            </div>
                        </div>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="text-center d-block" href="#">
                    <strong>See All Tasks</strong>
                    <i class="fa fa-angle-right"></i>
                </a>
            </li>
        </ul>
        <!-- /.dropdown-tasks -->
    </li>
    <!-- /.dropdown -->
    <li class="dropdown">
        <a class="dropdown-toggle" data-bs-toggle="dropdown" href="#">
            <i class="bi bi-bell-fill"></i> <i class="fa fa-caret-down"></i>
        </a>
        <ul class="dropdown-menu dropdown-alerts top-30 end-0">
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <i class="bi bi-chat-fill"></i> New Comment
                        <span class="pull-right text-muted small">4 minutes ago</span>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <i class="bi bi-twitter"></i> 3 New Followers
                        <span class="pull-right text-muted small">12 minutes ago</span>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <i class="bi bi-envelope-fill"></i> Message Sent
                        <span class="pull-right text-muted small">4 minutes ago</span>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <i class="fa fa-tasks fa-fw"></i> New Task
                        <span class="pull-right text-muted small">4 minutes ago</span>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="d-block" href="#">
                    <div>
                        <i class="bi bi-upload"></i> Server Rebooted
                        <span class="pull-right text-muted small">4 minutes ago</span>
                    </div>
                </a>
            </li>
            <li class="dropdown-divider"></li>
            <li class="dropdown-item p-0 my-4">
                <a class="text-center d-block" href="#">
                    <strong>See All Alerts</strong>
                    <i class="fa fa-angle-right"></i>
                </a>
            </li>
        </ul>
        <!-- /.dropdown-alerts -->
    </li>
    <!-- /.dropdown -->
    <li class="dropdown">
        <a class="dropdown-toggle" data-bs-toggle="dropdown" href="#">
            <i class="bi bi-person-fill"></i>
            {assign var="_user" value=$_acl->getCurrentUser()}
            {$_user->getFirstName()} <i class="fa fa-caret-down"></i>
        </a>
        <ul class="dropdown-menu dropdown-user top-30 end-0">
            {if !$_user->isGuest()}
                <li class="dropdown-item p-0 my-2"><a class="d-block" href="{$_application->getUrl('/authex/user/profile')}"><i class="bi bi-person-fill"></i> User
                        Profile</a>
                </li>
                <li class="dropdown-divider"></li>
                <li class="dropdown-item p-0 my-2">
                    <a class="d-block" href="{$_application->getUrl('/authex/user/logout', ['redirectUrl' => {$_application->getUrl($_route->getName())|base64_encode}])}"><i
                                class="bi bi-box-arrow-right"></i> Logout</a>
                </li>
            {else}
                <li class="dropdown-item p-0 my-2"><a class="d-block" href="{$_application->getUrl('/authex/user/login')}"><i class="bi bi-box-arrow-in-right"></i>
                        Login</a>
                </li>
            {/if}
        </ul>
        <!-- /.dropdown-user -->
    </li>
    <!-- /.dropdown -->
</ul>
<!-- /.navbar-top-links -->