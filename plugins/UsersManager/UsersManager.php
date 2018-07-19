<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\UsersManager;

use Exception;
use Piwik\API\Request;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\CoreHome\SystemSummary;
use Piwik\SettingsPiwik;

/**
 * Manage Piwik users
 *
 */
class UsersManager extends \Piwik\Plugin
{
    const PASSWORD_MIN_LENGTH = 6;

    /**
     * @see \Piwik\Plugin::registerEvents
     */
    public function registerEvents()
    {
        return array(
            'AssetManager.getJavaScriptFiles'        => 'getJsFiles',
            'AssetManager.getStylesheetFiles'        => 'getStylesheetFiles',
            'SitesManager.deleteSite.end'            => 'deleteSite',
            'Tracker.Cache.getSiteAttributes'        => 'recordAdminUsersInCache',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
            'Platform.initialized'                   => 'onPlatformInitialized',
            'System.addSystemSummaryItems'           => 'addSystemSummaryItems',
            'CronArchive.getTokenAuth'               => 'getCronArchiveTokenAuth'
        );
    }

    public function addSystemSummaryItems(&$systemSummary)
    {
        $userLogins = Request::processRequest('UsersManager.getUsersLogin', array('filter_limit' => '-1'));

        $numUsers = count($userLogins);
        if (in_array('anonymous', $userLogins)) {
            $numUsers--;
        }

        $systemSummary[] = new SystemSummary\Item($key = 'users', Piwik::translate('General_NUsers', $numUsers), $value = null, array('module' => 'UsersManager', 'action' => 'index'), $icon = 'icon-user', $order = 5);
    }

    public function onPlatformInitialized()
    {
        $lastSeenTimeLogger = new LastSeenTimeLogger();
        $lastSeenTimeLogger->logCurrentUserLastSeenTime();
    }

    /**
     * Hooks when a website tracker cache is flushed (website/user updated, cache deleted, or empty cache)
     * Will record in the tracker config file the list of Admin token_auth for this website. This
     * will be used when the Tracking API is used with setIp(), setForceDateTime(), setVisitorId(), etc.
     *
     * @param $attributes
     * @param $idSite
     * @return void
     */
    public function recordAdminUsersInCache(&$attributes, $idSite)
    {
        // add the 'hosts' entry in the website array
        $model = new Model();
        $logins = $model->getUsersLoginWithSiteAccess($idSite, 'admin');

        if (empty($logins)) {
            return;
        }

        $users = $model->getUsers($logins);

        $tokens = array();
        foreach ($users as $user) {
            $tokens[] = $user['token_auth'];
        }

        $attributes['admin_token_auth'] = $tokens;
    }

    public function getCronArchiveTokenAuth(&$tokens)
    {
        $model      = new Model();
        $superUsers = $model->getUsersHavingSuperUserAccess();

        foreach($superUsers as $superUser) {
            $tokens[] = $superUser['token_auth'];
        }
    }

    /**
     * Delete user preferences associated with a particular site
     */
    public function deleteSite($idSite)
    {
        Option::deleteLike('%\_' . API::PREFERENCE_DEFAULT_REPORT, $idSite);
    }

    /**
     * Return list of plug-in specific JavaScript files to be imported by the asset manager
     *
     * @see \Piwik\AssetManager
     */
    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/UsersManager/angularjs/users-manager/users-manager.component.js";
        $jsFiles[] = "plugins/UsersManager/angularjs/paged-users-list/paged-users-list.component.js";
        $jsFiles[] = "plugins/UsersManager/angularjs/user-edit-form/user-edit-form.component.js";
        $jsFiles[] = "plugins/UsersManager/angularjs/user-permissions-edit/user-permissions-edit.component.js";
        $jsFiles[] = "plugins/UsersManager/angularjs/personal-settings/personal-settings.controller.js";
        $jsFiles[] = "plugins/UsersManager/angularjs/personal-settings/anonymous-settings.controller.js";
    }

    /**
     * Get CSS files
     */
    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = "plugins/UsersManager/stylesheets/usersManager.less";

        $stylesheets[] = "plugins/UsersManager/angularjs/users-manager/users-manager.component.less";
        $stylesheets[] = "plugins/UsersManager/angularjs/paged-users-list/paged-users-list.component.less";
        $stylesheets[] = "plugins/UsersManager/angularjs/user-edit-form/user-edit-form.component.less";
        $stylesheets[] = "plugins/UsersManager/angularjs/user-permissions-edit/user-permissions-edit.component.less";
    }

    /**
     * Returns true if the password is complex enough (at least 6 characters and max 26 characters)
     *
     * @param $input string
     * @return bool
     */
    public static function isValidPasswordString($input)
    {
        if (!SettingsPiwik::isUserCredentialsSanityCheckEnabled()
            && !empty($input)
        ) {
            return true;
        }

        $l = strlen($input);

        return $l >= self::PASSWORD_MIN_LENGTH;
    }

    public static function checkPassword($password)
    {
        /**
         * Triggered before core password validator check password.
         *
         * This event exists for enable option to create custom password validation rules.
         * It can be used to validate password (length, used chars etc) and to notify about checking password.
         *
         * **Example**
         *
         *     Piwik::addAction('UsersManager.checkPassword', function ($password) {
         *         if (strlen($password) < 10) {
         *             throw new Exception('Password is too short.');
         *         }
         *     });
         *
         * @param string $password Checking password in plain text.
         */
        Piwik::postEvent('UsersManager.checkPassword', array($password));

        if (!self::isValidPasswordString($password)) {
            throw new Exception(Piwik::translate('UsersManager_ExceptionInvalidPassword', array(self::PASSWORD_MIN_LENGTH
            )));
        }
    }

    public static function getPasswordHash($password)
    {
        // if change here, should also edit the installation process
        // to change how the root pwd is saved in the config file
        return md5($password);
    }

    /**
     * Checks the password hash length. Used as a sanity check.
     *
     * @param string $passwordHash The password hash to check.
     * @param string $exceptionMessage Message of the exception thrown.
     * @throws Exception if the password hash length is incorrect.
     */
    public static function checkPasswordHash($passwordHash, $exceptionMessage)
    {
        if (strlen($passwordHash) != 32) {  // MD5 hash length
            throw new Exception($exceptionMessage);
        }
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = "General_OrCancel";
        $translationKeys[] = "General_Save";
        $translationKeys[] = "General_Done";
        $translationKeys[] = "UsersManager_DeleteConfirm";
        $translationKeys[] = "UsersManager_ConfirmGrantSuperUserAccess";
        $translationKeys[] = "UsersManager_ConfirmProhibitOtherUsersSuperUserAccess";
        $translationKeys[] = "UsersManager_ConfirmProhibitMySuperUserAccess";
        $translationKeys[] = "UsersManager_ExceptionUserHasViewAccessAlready";
        $translationKeys[] = "UsersManager_ExceptionNoValueForUsernameOrEmail";
        $translationKeys[] = "UsersManager_GiveUserAccess";
        $translationKeys[] = "UsersManager_PrivAdmin";
        $translationKeys[] = "UsersManager_PrivView";
        $translationKeys[] = "UsersManager_RemoveUserAccess";
        $translationKeys[] = "UsersManager_UserHasPermission";
        $translationKeys[] = "UsersManager_UserHasNoPermission";
        $translationKeys[] = "UsersManager_PrivNone";
        $translationKeys[] = "UsersManager_ManageUsers";
        $translationKeys[] = "UsersManager_ManageUsersDesc";
        $translationKeys[] = 'Mobile_NavigationBack';
        $translationKeys[] = 'UsersManager_AddExistingUser';
        $translationKeys[] = 'UsersManager_AddUser';
        $translationKeys[] = 'UsersManager_EnterUsernameOrEmail';
        $translationKeys[] = 'UsersManager_NoAccessWarning';
        $translationKeys[] = 'UsersManager_BulkActions';
        $translationKeys[] = 'UsersManager_SetPermission';
        $translationKeys[] = 'UsersManager_RolesHelp';
        $translationKeys[] = 'UsersManager_Role';
        $translationKeys[] = 'General_Actions';
        $translationKeys[] = 'UsersManager_TheDisplayedWebsitesAreSelected';
        $translationKeys[] = 'UsersManager_ClickToSelectAll';
        $translationKeys[] = 'UsersManager_AllWebsitesAreSelected';
        $translationKeys[] = 'UsersManager_ClickToSelectDisplayedWebsites';
        $translationKeys[] = 'UsersManager_DeletePermConfirmSingle';
        $translationKeys[] = 'UsersManager_DeletePermConfirmMultiple';
        $translationKeys[] = 'UsersManager_ChangePermToSiteConfirmSingle';
        $translationKeys[] = 'UsersManager_ChangePermToSiteConfirmMultiple';
        $translationKeys[] = 'UsersManager_BasicInformation';
        $translationKeys[] = 'UsersManager_Permissions';
        $translationKeys[] = 'UsersManager_RemovePermissions';
        $translationKeys[] = 'UsersManager_FirstSiteInlineHelp';
        $translationKeys[] = 'UsersManager_SuperUsersPermissionsNotice';
        $translationKeys[] = 'UsersManager_SuperUserIntro1';
        $translationKeys[] = 'UsersManager_SuperUserIntro2';
        $translationKeys[] = 'UsersManager_HasSuperUserAccess';
        $translationKeys[] = 'UsersManager_AreYouSure';
        $translationKeys[] = 'UsersManager_RemoveSuperuserAccessConfirm';
        $translationKeys[] = 'UsersManager_AddSuperuserAccessConfirm';
        $translationKeys[] = 'UsersManager_UserSearch';
        $translationKeys[] = 'UsersManager_DeleteUsers';
        $translationKeys[] = 'UsersManager_FilterByAccess';
        $translationKeys[] = 'UsersManager_XtoYofN';
        $translationKeys[] = 'UsersManager_Username';
        $translationKeys[] = 'UsersManager_RoleFor';
        $translationKeys[] = 'UsersManager_TheDisplayedUsersAreSelected';
        $translationKeys[] = 'UsersManager_AllUsersAreSelected';
        $translationKeys[] = 'UsersManager_ClickToSelectDisplayedUsers';
        $translationKeys[] = 'UsersManager_RemoveAllAccessToThisSite';
        $translationKeys[] = 'UsersManager_DeleteUserConfirmSingle';
        $translationKeys[] = 'UsersManager_DeleteUserConfirmMultiple';
        $translationKeys[] = 'UsersManager_DeleteUserPermConfirmSingle';
        $translationKeys[] = 'UsersManager_DeleteUserPermConfirmMultiple';
        $translationKeys[] = 'UsersManager_AddNewUser';
        $translationKeys[] = 'UsersManager_EditUser';
        $translationKeys[] = 'UsersManager_CreateUser';
        $translationKeys[] = 'UsersManager_SaveBasicInfo';
        $translationKeys[] = 'UsersManager_Email';
        $translationKeys[] = 'UsersManager_LastSeen';
        $translationKeys[] = 'UsersManager_SuperUserAccess';
        $translationKeys[] = 'General_Warning';
    }
}
