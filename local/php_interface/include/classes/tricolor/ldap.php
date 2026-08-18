<?
/**
 * Bitrix Framework
 * @package    sb
 * @subpackage tricolor
 * @copyright  2020 ГК Софтбаланс
 */


            // КОСТЫЛЬ 01.07.2026:
                // Пользователи, которых нельзя автоматически назначать руководителями,
                // даже если они единственные активные сотрудники в подразделении. Пользователи указываются в $arSingleEmployeeHeadExcludeUsers


namespace Tricolor;

use \Bitrix\Main\{
    Loader,
        Localization\Loc,
        UserTable
};

use CLdapServer,
        CTimeZone,
        CUser,
        CModule,
        COption,
        CIBlockSection;

Loader::includeModule('ldap');
Loader::includeModule('tricolor.trs');

Loc::loadMessages(__FILE__);

/**
 * Класс для работы с модулем ldap.
 *
 * @package    sb
 * @subpackage tricolor
 * @category   Ldap
 */
class Ldap extends CLdapServer
{
        public static function isDebugLogin($login)
        {
                return in_array($login, [
                        'admin',
                        'ZakharovM',
                        'LobachAV'
                ]);
        }

        public static function log($method, $login, $data, $title = '')
        {
                return false;

                if (self::isDebugLogin($login)) {

                        $dateTime = new \Bitrix\Main\Type\DateTime();

                        // \Bitrix\Main\Diag\Debug::writeToFile(
                        //      $data,
                        //      $dateTime->format('d.m.Y H:i:s') . ' ' . $method . ' ' . $title,
                        //      '/upload/trs_log/ldap/TRICOLOR_LDAP_LOG__'.$login.'__'.$method.'__' . $dateTime->format('d-m-Y') . '__log.txt'
                        // );

                }
        }

        public static function getUserFields($arLdapUser, &$departmentCache = false, $oLdapServer)
        {

                $arLog = [
                        '$arLdapUser' => $arLdapUser,
                        '$departmentCache' => $departmentCache,
                        '$oLdapServerFields' => $oLdapServer->arFields,
                        'trace' => \Bitrix\Main\Diag\Helper::getBackTrace()
                ];

                self::log('getUserFields', $arLdapUser[$oLdapServer->arFields['USER_ID_ATTR']], $arLog);

                global $APPLICATION;

                $arFields = array(
                        'DN'                            => $arLdapUser['dn'],
                        'LOGIN'                         => $arLdapUser[strtolower($oLdapServer->arFields['~USER_ID_ATTR'])],
                        'EXTERNAL_AUTH_ID'      => 'LDAP#'.$oLdapServer->arFields['ID'],
                        'LDAP_GROUPS'           => $arLdapUser[strtolower($oLdapServer->arFields['~USER_GROUP_ATTR'])],
                );

                // for each field, do the conversion

                foreach($oLdapServer->arFields["FIELD_MAP"] as $userField=>$attr)
                        $arFields[$userField] = $oLdapServer->getLdapValueByBitrixFieldName($userField, $arLdapUser);

                $APPLICATION->ResetException();
                $db_events = GetModuleEvents("ldap", "OnLdapUserFields");
                while($arEvent = $db_events->Fetch())
                {
                        $arParams = array(array(&$arFields, &$arLdapUser));
                        if(ExecuteModuleEventEx($arEvent, $arParams)===false)
                        {
                                if(!($err = $APPLICATION->GetException()))
                                        $APPLICATION->ThrowException("Unknown error");
                                return false;
                        }
                        $arFields = $arParams[0][0];
                }

                // set a department field, if needed
                if (empty($arFields['UF_DEPARTMENT']) && isModuleInstalled('intranet')
                        && $oLdapServer->arFields['IMPORT_STRUCT'] && $oLdapServer->arFields['IMPORT_STRUCT']=='Y')
                {
                        $username = $arLdapUser[$oLdapServer->arFields['USER_ID_ATTR']];
                        if ($arDepartment = self::getDepartmentIdForADUser($arLdapUser[$oLdapServer->arFields['USER_DEPARTMENT_ATTR']],$arLdapUser[$oLdapServer->arFields['USER_MANAGER_ATTR']],$username,$departmentCache,false,false,$oLdapServer))
                        {
                                // fill in cache. it is done outside the function because it has many exit points
                                if ($departmentCache)
                                        $departmentCache[$username] = $arDepartment;

                                // this is not final assignment
                                // $arFields['UF_DEPARTMENT'] sould contain array of department ids
                                // but somehow we have to return an information whether this user is a department head
                                // so we'll save this data here temporarily
                                $arFields['UF_DEPARTMENT'] = $arDepartment;
                        }
                        else
                                $arFields['UF_DEPARTMENT'] = array();

                        // at this point $arFields['UF_DEPARTMENT'] should be set to some value, even an empty array is ok
                }

                if (!is_array($arFields['LDAP_GROUPS']))
                        $arFields['LDAP_GROUPS'] = (!empty($arFields['LDAP_GROUPS']) ? array($arFields['LDAP_GROUPS']) : array());

                $primarygroupid_name_attr = 'primarygroupid';
                $primarygrouptoken_name_attr = 'primarygrouptoken';

                $groupMemberAttr = null;
                $userIdAttr = null;

                if ($oLdapServer->arFields['USER_GROUP_ACCESSORY'] == 'Y')
                {
                        $primarygroupid_name_attr = strtolower($oLdapServer->arFields['GROUP_ID_ATTR']);
                        $primarygrouptoken_name_attr = strtolower($oLdapServer->arFields['USER_GROUP_ATTR']);
                        $userIdAttr = strtolower($oLdapServer->arFields['USER_ID_ATTR']);
                        $groupMemberAttr = strtolower($oLdapServer->arFields['GROUP_MEMBERS_ATTR']);
                }

                $arAllGroups = $oLdapServer->GetGroupListArray();

                $arLog['arFields'] = $arFields;
                self::log('getUserFields', $arLdapUser[$oLdapServer->arFields['USER_ID_ATTR']], $arLog);


                if (!is_array($arAllGroups) || count($arAllGroups) <= 0)
                        return $arFields;

                $arGroup = reset($arAllGroups);

                do
                {
                        if(in_array($arGroup['ID'], $arFields['LDAP_GROUPS']))
                                continue;

                        if      (
                                        (is_set($arLdapUser, $primarygroupid_name_attr)
                                        && $arGroup[$primarygrouptoken_name_attr] == $arLdapUser[$primarygroupid_name_attr]
                                        )
                                        ||
                                        ($oLdapServer->arFields['USER_GROUP_ACCESSORY'] == 'Y'
                                        && is_set($arGroup, $groupMemberAttr)
                                        && (
                                                        (is_array($arGroup[$groupMemberAttr])
                                                        && in_array($arLdapUser[$userIdAttr], $arGroup[$groupMemberAttr])
                                                        )
                                                ||
                                                $arLdapUser[$userIdAttr] == $arGroup[$groupMemberAttr]
                                                )
                                        )
                                )

                        {
                                $arFields['LDAP_GROUPS'][] = $arGroup['ID'];
                                if ($oLdapServer->arFields['USER_GROUP_ACCESSORY'] == 'N')
                                        break;
                        }
                }
                while ($arGroup = next($arAllGroups));

                return $arFields;
        }

        // Gets department ID for AD user. If department doesn't exist, creates a new one. Returns FALSE if there should be no department set.
        // returns array:
        // 'ID' - department id
        // 'IS_HEAD' - true if this user is head of the department, false if not
        public static function getDepartmentIdForADUser($department, $managerDN, $username, &$cache=FALSE, $iblockId = FALSE, $names = FALSE, $oLdapServer)
        {

                $arLog = [
                        '$department' => $department,
                        '$managerDN' => $managerDN,
                        '$username' => $username,
                        '$cache' => $cache,
                        '$iblockId' => $iblockId,
                        '$names' => $names,
                        '$oLdapServerFields' => $oLdapServer->arFields,
                        'trace' => \Bitrix\Main\Diag\Helper::getBackTrace()
                ];

                self::log('getDepartmentIdForADUser', $username, $arLog, 'START');

                global $USER_FIELD_MANAGER;

                // check for loops in manager structure, if loop is found - quit
                // should be done before cache lookup
                if ($names && isset($names[$username]))
                        return false;

                // if department id for this user is already stored in cache
                if ($cache)
                {
                        $departmentCached = $cache[$username];
                        // if user was not set as head earlier, then do not get his id from cache
                        if ($departmentCached)
                                return $departmentCached;
                }

                // if it is a first call in recursive chain
                if (!$iblockId)
                {
                        // check module inclusions
                        if (!IsModuleInstalled('intranet') || !Loader::includeModule('iblock'))
                                return false;

                        // get structure's iblock id
                        $iblockId=COption::GetOptionInt("intranet", "iblock_structure",  false, false);
                        if (!$iblockId)
                                return false;

                        $names = array();
                }

                // save current username as already visited
                $names[$username] = true;

                $arManagerDep = null;
                $mgrDepartment = null;

                // if there's a manager - query it
                if ($managerDN)
                {
                        preg_match('/^((CN|uid)=.*?)(\,){1}([^\,])*(=){1}/i', $managerDN, $matches); //Extract "CN=User Name" from full name
                        $user = isset($matches[1]) ? str_replace('\\', '',$matches[1]) : "";
                        $userArr = $oLdapServer->GetUserArray($user);

                        if (is_array($userArr) && count($userArr) > 0)
                        {
                                // contents of userArr are already in local encoding, no need for conversion here
                                $mgrDepartment = $userArr[0][$oLdapServer->arFields['USER_DEPARTMENT_ATTR']];
                                if ($mgrDepartment && trim($mgrDepartment)!='')
                                {
                                        // if manager's department name is set - then get it's id
                                        $mgrManagerDN = $userArr[0][$oLdapServer->arFields['USER_MANAGER_ATTR']];
                                        $mgrUserName = $userArr[0][$oLdapServer->arFields['USER_ID_ATTR']];
                                        $arManagerDep = self::getDepartmentIdForADUser($mgrDepartment, $mgrManagerDN, $mgrUserName, $cache, $iblockId, $names, $oLdapServer);
                                        // fill in cache
                                        if ($cache && $arManagerDep)
                                                $cache[$mgrUserName] = $arManagerDep;
                                }
                        }
                }

                // prepare result and create department (if needed)
                $arResult = array('IS_HEAD'=>true); // by default, thinking of user as a head of the department

                if ($arManagerDep)
                {
                        // if got manager's data correctly
                        if ($department && trim($department)!='' && ($mgrDepartment!=$department))
                        {
                                // if our department is set && differs from manager's, set manager's as parent
                                $parentSectionId = $arManagerDep['ID'];
                        }
                        else
                        {
                                // - if user has no department, but somehow have manager - then he is assumed to be in manager's department
                                // - if user has same department name as manager - then he is not head
                                // here we can return manager's department id immediately
                                $arResult = $arManagerDep;
                                $arResult['IS_HEAD'] = false;
                                return $arResult;
                        }
                }
                else
                {
                        // if there's no manager's data
                        if ($department && trim($department)!='')
                        {
                                $parentSectionId = $oLdapServer->arFields['ROOT_DEPARTMENT'];
                        }
                        else
                        {
                                // if have no manager's department and no own department:
                                // - use default as our department and root as parent section if default is set
                                // - or just root if default has empty value
                                // - or return false, if setting of default department is turned off
                                if ($oLdapServer->arFields['STRUCT_HAVE_DEFAULT'] && $oLdapServer->arFields['STRUCT_HAVE_DEFAULT'] == "Y")
                                {
                                        // if can use default department
                                        $department = $oLdapServer->arFields['DEFAULT_DEPARTMENT_NAME'];

                                        if ($department && trim($department)!='')
                                        {
                                                // if department is not empty
                                                $parentSectionId = $oLdapServer->arFields['ROOT_DEPARTMENT'];
                                        }
                                        else
                                        {
                                                // if it is empty - return parent
                                                return array('ID' => $oLdapServer->arFields['ROOT_DEPARTMENT']);
                                        }
                                }
                                else
                                {
                                        // if have no department in AD and no default - then do not set a department
                                        return false;
                                }
                        }
                }
                // 3. if there's no department set for this user, this means there was no default department name (which substituted in *) - then there's no need to set department id for this user at all
                if (!$department || trim($department)=='')
                        return false;

                // 4. detect this user's department ID, using parent id and department name string, which we certainly have now (these 2 parameters are required to get an ID)

                // see if this department already exists
                $bs = new CIBlockSection();
                $dbExistingSections = GetIBlockSectionList(
                        $iblockId,
                        ($parentSectionId >= 0 ? $parentSectionId : false),
                        $arOrder = Array("left_margin" => "asc"),
                        $cnt = 0,
                        $arFilter = Array('NAME' => $department)
                );

                $departmentId = false;
                if($arItem = $dbExistingSections->GetNext())
                        $departmentId = $arItem['ID'];
                if (!$departmentId)
                {
                        //create new department
                        $arNewSectFields = Array(
                                "ACTIVE" => "Y",
                                "IBLOCK_ID" => $iblockId,
                                "NAME" => $department
                        );
                        if ($parentSectionId>=0)
                                $arNewSectFields["IBLOCK_SECTION_ID"] = $parentSectionId;
                        // and get it's Id
                        $departmentId = $bs->Add($arNewSectFields);
                }

                $arElement = $USER_FIELD_MANAGER->GetUserFields(
                        'IBLOCK_'.$iblockId.'_SECTION',
                        $departmentId
                );

                // if the head of the department is already set, do not change it
                // if (!empty($arElement['UF_HEAD']['VALUE']))
                //      $arResult['IS_HEAD'] = false;

                $arResult['ID'] = $departmentId;


                $arLog['arResult'] = $arResult;
                self::log('getDepartmentIdForADUser', $username, $arLog, 'END');

                return $arResult;
        }

        public static function getStructure()
        {
                if (!IsModuleInstalled('intranet'))
                {
                        return false;
                }

                $arResult = [];

                if (Loader::includeModule('iblock'))
                {
                        $iblockId = COption::GetOptionInt("intranet", "iblock_structure", false, false);
                        if ($iblockId)
                        {
                                $entity = \Bitrix\Iblock\Model\Section::compileEntityByIblock($iblockId);

                                $list = $entity::getList(array(
                                        "filter" => array(
                                                "IBLOCK_ID" => $iblockId
                                        ),
                                        "select" => array("ID", "NAME", "UF_HEAD"),
                                ));

                                while($row = $list->fetch())
                                {
                                        $arResult[] = $row;
                                }
                        }
                }
                return $arResult;
        }

        public static function CheckDependencesAgent()
        {
                self::CheckDependences();
                return "\Tricolor\Ldap::CheckDependencesAgent();";
        }

        public static function CheckDependences()
        {
                $isCustomHandlerExists = false;

                foreach(GetModuleEvents("main", "OnBeforeProlog", true) as $arEvent) {

                        if (
                                $arEvent["TO_CLASS"] === 'CLDAP' &&
                                $arEvent["TO_METHOD"] === 'NTLMAuth'

                        ) {
                                UnRegisterModuleDependences('main', 'OnBeforeProlog', 'ldap', 'CLDAP', 'NTLMAuth');
                        }

                        if (
                                $arEvent["TO_CLASS"] === '\Tricolor\Ldap' &&
                                $arEvent["TO_METHOD"] === 'NTLMAuth'

                        ) {
                                $isCustomHandlerExists = true;
                        }

                }

                if (!$isCustomHandlerExists) {
                        \Bitrix\Main\EventManager::getInstance()->registerEventHandler('main', 'OnBeforeProlog', 'tricolor.trs', '\Tricolor\Ldap', 'NTLMAuth');
                }

        }

        public static function SyncAgent($id)
        {
                self::Sync($id);
                return "\Tricolor\Ldap::SyncAgent(".$id.");";
        }

        public static function Sync($ldap_server_id)
        {
                $arLog = [];

                global $DB, $USER, $APPLICATION;
                $bUSERGen = false;
                self::$syncErrors = array();

                if(!is_object($USER))
                {
                        $USER = new CUser();
                        $bUSERGen = true;
                }

                $dbLdapServers = parent::GetByID($ldap_server_id);
                if(!($oLdapServer = $dbLdapServers->GetNextServer()))
                        return false;

                if(!$oLdapServer->Connect())
                        return false;

                if(!$oLdapServer->BindAdmin())
                {
                        $oLdapServer->Disconnect();
                        return false;
                }

                $APPLICATION->ResetException();
                $db_events = GetModuleEvents("ldap", "OnLdapBeforeSync");

                while($arEvent = $db_events->Fetch())
                {
                        $arParams['oLdapServer'] = $oLdapServer;

                        if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
                        {
                                if(!($err = $APPLICATION->GetException()))
                                        $APPLICATION->ThrowException("Unknown error");

                                return false;
                        }
                }

                // select all users from LDAP
                $arLdapUsers = array();
                $ldapLoginAttr = strtolower($oLdapServer->arFields["~USER_ID_ATTR"]);
                $ldapManagerAttr = strtolower($oLdapServer->arFields["~USER_MANAGER_ATTR"]);
                $ldapDepAttr = strtolower($oLdapServer->arFields["~USER_DEPARTMENT_ATTR"]);

                $APPLICATION->ResetException();
                $dbLdapUsers = $oLdapServer->GetUserList();
                $ldpEx = $APPLICATION->GetException();

                while($arLdapUser = $dbLdapUsers->Fetch())
                        $arLdapUsers[strtolower($arLdapUser[$ldapLoginAttr])] = $arLdapUser;

                unset($dbLdapUsers);

        // get managers array
        $arManagers = array();
        $arDepartments = array();
        $arUsersIdKey = array();
        foreach($arLdapUsers as $key => $arUserItem)
        {
                        if(!empty($arUserItem[$ldapManagerAttr]) && !in_array($arUserItem[$ldapManagerAttr], $arManagers))
                                $arManagers[] = $arUserItem[$ldapManagerAttr];

                        if(!empty($arUserItem[$ldapDepAttr]) && !in_array($arUserItem[$ldapDepAttr], $arDepartments))
                                $arDepartments[] = $arUserItem[$ldapDepAttr];
                }

                // select all Bitrix CMS users for this LDAP
                $arUsers = Array();

                CTimeZone::Disable();
                $dbUsers = CUser::GetList(($o=""), ($b=""), Array("EXTERNAL_AUTH_ID"=>"LDAP#".$ldap_server_id));
                CTimeZone::Enable();

                while($arUser = $dbUsers->Fetch())
                        $arUsers[strtolower($arUser["LOGIN"])] = $arUser;

                unset($dbUsers);

                $arDelLdapUsers = array();

                if(!$ldpEx || $ldpEx->msg != 'LDAP_SEARCH_ERROR')
                        $arDelLdapUsers = array_diff(array_keys($arUsers), array_keys($arLdapUsers));

                if(strlen($oLdapServer->arFields["SYNC_LAST"]) > 0)
                        $syncTime = MakeTimeStamp($oLdapServer->arFields["SYNC_LAST"]);
                else
                        $syncTime = 0;

                $cnt = 0;
                $departmentCache = array();

                $arLog['arLdapUsers'] = $arLdapUsers;

                foreach($arLog['arLdapUsers'] as $l => $arLogUser)
                {
                        unset($arLog['arLdapUsers'][$l]['usercertificate']);
                        unset($arLog['arLdapUsers'][$l]['thumbnailphoto']);
                        unset($arLog['arLdapUsers'][$l]['msexchmailboxsecuritydescriptor']);
                        unset($arLog['arLdapUsers'][$l]['msexchmailboxguid']);
                        unset($arLog['arLdapUsers'][$l]['objectguid']);
                        unset($arLog['arLdapUsers'][$l]['objectsid']);
                }

                //$arLog['arUsers'] = $arUsers;

                // have to update $oLdapServer->arFields["FIELD_MAP"] for user fields
                // for each one of them looking for similar in user list
                foreach($arLdapUsers as $userLogin => $arLdapUserFields)
                {
                        if(!is_array($arUsers[$userLogin]))
                        {
                                //For manual users import - always add
                                if($oLdapServer->arFields["SYNC_USER_ADD"] != "Y")
                                        continue;

                                // if user is not found among already existing ones, then import him
                                // $arLdapUserFields - user fields from ldap
                                $userActive = $oLdapServer->getLdapValueByBitrixFieldName("ACTIVE", $arLdapUserFields);

                                if($userActive != "Y")
                                        continue;

                                //$arUserFields = $oLdapServer->GetUserFields($arLdapUserFields, $departmentCache);
                                $arUserFields = self::getUserFields($arLdapUserFields, $departmentCache, $oLdapServer);

                                if(self::isUserInBannedGroups($ldap_server_id, $arUserFields))
                    continue;

                // if user is not a manager, he can't be a head of department
                                if(!in_array($arLdapUserFields['distinguishedname'], $arManagers))
                                {
                                        $arUserFields['UF_DEPARTMENT']['IS_HEAD'] = false;
                                }

                                if($oLdapServer->SetUser($arUserFields))
                                        $cnt++;
                        }
                        else
                        {
                                // if date of update is set, then compare it
                                $ldapTime = time();

                                if($syncTime > 0
                                        && strlen($oLdapServer->arFields["SYNC_ATTR"])>0
                                        && preg_match("'([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\.0Z'", $arLdapUserFields[strtolower($oLdapServer->arFields["SYNC_ATTR"])], $arTimeMatch)
                                        )
                                {
                                        $ldapTime = gmmktime($arTimeMatch[4], $arTimeMatch[5], $arTimeMatch[6], $arTimeMatch[2], $arTimeMatch[3], $arTimeMatch[1]);
                                        $userTime = MakeTimeStamp($arUsers[$userLogin]["TIMESTAMP_X"]);
                                }

                                // if($syncTime < $ldapTime || $syncTime < $userTime)
                                // {
                                        //$arUserFields = $oLdapServer->GetUserFields($arLdapUserFields, $departmentCache);
                                        $arUserFields = self::getUserFields($arLdapUserFields, $departmentCache, $oLdapServer);

                                        if(self::isUserInBannedGroups($ldap_server_id, $arUserFields))
                                                continue;

                    $arUserFields["ID"] = $arUsers[$userLogin]["ID"];

                    // if user is not a manager, he can't be a head of department
                                        if(!in_array($arLdapUserFields['distinguishedname'], $arManagers))
                                        {
                                                $arUserFields['UF_DEPARTMENT']['IS_HEAD'] = false;
                                                $arUsersIdKey[$arUsers[$userLogin]["ID"]]['IS_MANAGER'] = false;
                                        }
                                        else
                                        {
                                                //$arUsers[$userLogin]['IS_MANAGER'] = true;
                                                $arUsersIdKey[$arUsers[$userLogin]["ID"]]['IS_MANAGER'] = true;
                                        }

                                        if($oLdapServer->SetUser($arUserFields))
                                                $cnt++;
                                // }
                        }

                        if($USER->LAST_ERROR != '')
                        {
                                self::$syncErrors[] = $userLogin.': '.$USER->LAST_ERROR;
                                $USER->LAST_ERROR = '';
                        }
                }

                foreach ($arDelLdapUsers as $userLogin)
                {
                        $USER = new CUser();
                        if (isset($arUsers[$userLogin]) && $arUsers[$userLogin]['ACTIVE'] == 'Y')
                        {
                                $ID = intval($arUsers[$userLogin]["ID"]);
                                $USER->Update($ID, array('ACTIVE' => 'N'));
                        }
                }

                // получаем массив подразделений и сотрудников
                $arUserDep = self::getDepartmentUsers();

                // КОСТЫЛЬ:
                // Пользователи, которых нельзя автоматически назначать руководителями,
                // даже если они единственные активные сотрудники в подразделении.
                // Добавляйте сюда ID пользователей Bitrix.
                $arSingleEmployeeHeadExcludeUsers = [
                        6469,
                        // 1234,
                        // 5678,
                ];

                // собираем массив подразделений, в которых один сотрудник
                $arOneEmpDep = [];
                foreach($arUserDep as $dId => $arDepItem)
                {
                        if (is_array($arDepItem) && count($arDepItem) == 1) {
                                $singleUserId = (int)reset($arDepItem);

                                if (in_array($singleUserId, $arSingleEmployeeHeadExcludeUsers, true)) {
                                        continue;
                                }

                                $arOneEmpDep[$dId] = $singleUserId;
                        }
                }

                $arStructure = self::getStructure();

                // clear department's head if user is not a manager in AD
                foreach($arStructure as $arSection)
                {
                        if($arSection['UF_HEAD'] && !$arUsersIdKey[$arSection['UF_HEAD']]['IS_MANAGER'])
                        {
                                $obS = new CIBlockSection();
                                $obS->Update($arSection['ID'], array('UF_HEAD' => ''), false, false);
                        }

                        // если в подразделении один сотрудник и нет руководителя, назначаем его руководителем
                        if($arOneEmpDep[$arSection['ID']])
                        {
                                $obS = new CIBlockSection();
                                $obS->Update($arSection['ID'], array('UF_HEAD' => $arOneEmpDep[$arSection['ID']]), false, false);
                        }

                        if($arSection['NAME'] && !in_array($arSection['NAME'], $arDepartments))
                        {
                                $arDelSections[] = $arSection;
                        }
                }

                foreach($arDelSections as $arDelSection)
                {
                        if($arDelSection['ID'] > 0)
                        {
                                $DB->StartTransaction();
                                if(!CIBlockSection::Delete($arDelSection['ID']))
                                {
                                        //$strWarning .= 'Error.';
                                        $DB->Rollback();
                                }
                                else
                                        $DB->Commit();
                        }
                }

                $oLdapServer->Disconnect();
                CLdapServer::Update($ldap_server_id, Array("~SYNC_LAST"=>$DB->CurrentTimeFunction()));

                if(Loader::includeModule('intranet'))
                {
                        //\Bitrix\Intranet\Internals\UserSubordinationTable::performReInitialization();
                        //\Bitrix\Intranet\Internals\UserToDepartmentTable::performReInitialization();
                }

                if($bUSERGen)
                        unset($USER);

                $dateTime = new \Bitrix\Main\Type\DateTime();
                $arLog['arTrace'] = \Bitrix\Main\Diag\Helper::getBackTrace();

                // \Bitrix\Main\Diag\Debug::writeToFile(
                //      $arLog,
                //      $dateTime->format('d.m.Y H:i:s') . ' Ldap Sync',
                //      '/upload/trs_log/ldap/Sync__' . $dateTime->format('d-m-Y__H-i-s') . '__log.txt'
                // );

                return $cnt;
        }

        public static function getDepartmentUsers($departmentId=false)
        {
                $arResult = [];

                $arParams = [
                        'filter' => ['ACTIVE' => 'Y', '!UF_DEPARTMENT' => false],
                        'select' => ['ID', 'UF_DEPARTMENT']
                ];

                if ($departmentId) {
                        unset($arParams['filter']['!UF_DEPARTMENT']);
                        $arParams['filter']['UF_DEPARTMENT'] = $departmentId;
                }

                $list = UserTable::getList($arParams);

                while ($row = $list->fetch())
                {
                        foreach($row['UF_DEPARTMENT'] as $depId)
                        {
                                $arResult[$depId][] = $row['ID'];
                        }
                }

                return $arResult;
        }

        // метод для проверки, является ли пользователь единственным в подразделении (а следовательно, руководителем)
        public static function isOneUser($login) {

                $arUser = UserTable::getRow([
                        'filter' => [
                                'LOGIN' => $login
                        ],
                        'select' => [
                                'ID', 'UF_DEPARTMENT'
                        ]
                ]);

                $arDep = self::getDepartmentUsers($arUser['UF_DEPARTMENT']);

                foreach ($arUser['UF_DEPARTMENT'] as $depId) {
                        if (is_array($arDep[$depId]) && count($arDep[$depId]) == 1)
                                return true;
                }

                return false;
        }

        protected function getManagers($oLdapServer) {

                $arLdapUsers = [];
                $dbLdapUsers = $oLdapServer->GetUserList();
                $ldapLoginAttr = strtolower($oLdapServer->arFields["~USER_ID_ATTR"]);
                $ldapManagerAttr = strtolower($oLdapServer->arFields["~USER_MANAGER_ATTR"]);

                while($arLdapUser = $dbLdapUsers->Fetch())
                        $arLdapUsers[strtolower($arLdapUser[$ldapLoginAttr])] = $arLdapUser;

                unset($dbLdapUsers);

        // get managers array
        $arManagers = array();
        foreach($arLdapUsers as $key => $arUserItem)
        {
                        if(!empty($arUserItem[$ldapManagerAttr]) && !in_array($arUserItem[$ldapManagerAttr], $arManagers))
                                $arManagers[] = $arUserItem[$ldapManagerAttr];
                }

                return $arManagers;
        }

        // переопределенный метод класса Cldap,
        // перед SetUser установлена проверка менеджеров
        public static function OnFindExternalUser($login)
        {
                $arLog = [];
                $arLog['login'] = $login;

                $arLog['arTrace'] = \Bitrix\Main\Diag\Helper::getBackTrace();
                self::log('OnFindExternalUser', $login, $arLog, 'START');


                if(strlen($login) <= 0)
                        return 0;

                $filter = array("ACTIVE" => "Y");
                $p = strpos($login, "\\");

                if($p === false && COption::GetOptionString("ldap", "ntlm_auth_without_prefix", "Y") != "Y")
                {
                        return 0;
                }
                elseif( $p > 0 )
                {
                        $filter["CODE"] = substr($login, 0, $p);
                        $login = substr($login, $p+1);
                }

                $dbServ = CLdapServer::GetList(array(), $filter);

                while($serv = $dbServ->GetNextServer())
                {
                        if($serv->Connect())
                        {
                                if($arLdapUser = $serv->FindUser($login))
                                {
                                        // не устанавливаем руководителя отдела, чтобы не сбивать структуру, устаноленную агентом
                                        $arLdapUser['UF_DEPARTMENT']['IS_HEAD'] = self::isOneUser($login);
                                        self::log('OnFindExternalUser', $login, ['arLdapUser' => $arLdapUser], 'before set user');

                                        $id = $serv->SetUser($arLdapUser, (COption::GetOptionString("ldap", "add_user_when_auth", "Y") == "Y"));
                                        self::log('OnFindExternalUser', $login, ['id' => $id], 'after set user');

                                        if($id > 0)
                                        {
                                                $serv->Disconnect();
                                                return $id;
                                        }
                                }

                                $serv->Disconnect();
                        }
                }

                return 0;
        }

        public static function OnUserLogin($arArgs)
        {
                $arLog = [];
                $arLog['arArgs'] = $arArgs;


                $arLog['arTrace'] = \Bitrix\Main\Diag\Helper::getBackTrace();
                self::log('OnUserLogin', $arArgs['LOGIN'], $arLog, 'STEP 1');


                global $APPLICATION;

                if(!function_exists("ldap_connect"))
                        return false;

                $LOGIN = $arArgs["LOGIN"];
                $PASSWORD = $arArgs["PASSWORD"];

                self::log('OnUserLogin', $arArgs['LOGIN'], [
                        'LOGIN' => $LOGIN,
                        'PASSWORD' => $PASSWORD
                ], 'STEP 2');

                if(strlen($LOGIN)<=0 || strlen($PASSWORD)<=0)
                        return false;


                self::log('OnUserLogin', $arArgs['LOGIN'], [], 'STEP 3');


                $arFilter = Array("ACTIVE"=>"Y");
                $p = strpos($LOGIN, "\\");

                if( $p===false && COption::GetOptionString("ldap", "ntlm_auth_without_prefix", "Y") != "Y")
                {
                        return false;
                }
                elseif( $p > 0 )
                {
                        $arFilter["CODE"] = substr($LOGIN, 0, $p);
                        $LOGIN = substr($LOGIN, $p+1);
                }

                $arParams = Array(
                        "LOGIN" => &$LOGIN,
                        "PASSWORD" => &$PASSWORD,
                        "LDAP_FILTER" => &$arFilter,
                );

                self::log('OnUserLogin', $arArgs['LOGIN'], ['$arParams' => $arParams], 'STEP 4');


                $APPLICATION->ResetException();
                foreach(GetModuleEvents("ldap", "OnBeforeUserLogin", true) as $arEvent)
                {
                        // TODO check whether wrapping of &$arParams into another array is reasonable as part of migration from ExecuteModuleEvent to ExecuteModuleEventEx
                        if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
                        {
                                if($err = $APPLICATION->GetException())
                                {
                                        $result_message = Array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
                                }
                                else
                                {
                                        $APPLICATION->ThrowException("Unknown error");
                                        $result_message = Array("MESSAGE"=>"Unknown error"."<br>", "TYPE"=>"ERROR");
                                }

                                return false;
                        }
                }

                self::log('OnUserLogin', $arArgs['LOGIN'], ['$arFilter' => $arFilter], 'STEP 5');


                $db_ldap_serv = CLdapServer::GetList(Array(), $arFilter);

                while($xLDAP = $db_ldap_serv->GetNextServer())
                {
                        if($xLDAP->Connect())
                        {
                                // user AD parameters are queried here, inside FindUser function
                                if(!$arLdapUser = $xLDAP->FindUser($LOGIN, $PASSWORD))
                                {

                                        if(isset($arArgs["OTP"]) && strlen($arArgs["OTP"]) > 0)
                                                if(substr($PASSWORD, -6) == $arArgs["OTP"])
                                                        $arLdapUser = $xLDAP->FindUser($LOGIN, substr($PASSWORD, 0, -6));       //It can be with otp
                                }

                                self::log('OnUserLogin', $arArgs['LOGIN'], [], 'STEP 6');


                                if($arLdapUser)
                                {
                                        // не устанавливаем руководителя отдела, чтобы не сбивать структуру, устаноленную агентом
                                        $arLdapUser['UF_DEPARTMENT']['IS_HEAD'] = self::isOneUser($LOGIN);

                                        $arLog['arLdapUser'] = $arLdapUser;
                                        self::log('OnUserLogin', $arArgs['LOGIN'], ['arLdapUser' => $arLdapUser], 'fefore set user');

                                        $ID = $xLDAP->SetUser($arLdapUser, (COption::GetOptionString("ldap", "add_user_when_auth", "Y")=="Y"));
                                        self::log('OnUserLogin', $arArgs['LOGIN'], ['ID' => $ID], 'after set user');

                                        if($ID > 0)
                                        {
                                                $arArgs["STORE_PASSWORD"] = "N";
                                                $xLDAP->Disconnect();
                                                return $ID;
                                        }
                                }

                                $xLDAP->Disconnect();
                        }
                }

                return false;
        }

        static function NTLMAuth()
        {
                $arLog = [];

                $dateTime = new \Bitrix\Main\Type\DateTime();
                $arLog['arTrace'] = \Bitrix\Main\Diag\Helper::getBackTrace();

                self::log('NTLMAuth', 'admin', $arLog, 'step 1');

                global $USER;

                if ($USER->IsAuthorized())
                        return;

                if(!array_key_exists("AUTH_TYPE", $_SERVER) || ($_SERVER["AUTH_TYPE"] != "NTLM" && $_SERVER["AUTH_TYPE"] != "Negotiate"))
                        return;

                self::log('NTLMAuth', 'admin', $arLog, 'step 2');

                $ntlm_varname = trim(COption::GetOptionString('ldap', 'ntlm_varname', 'REMOTE_USER'));

                if (array_key_exists($ntlm_varname, $_SERVER) && strlen($LOGIN = $_SERVER[$ntlm_varname]) > 0)
                {
                        $DOMAIN = "";

                        if(($pos = strpos($LOGIN, "\\")) !== false)
                        {
                                $DOMAIN = substr($LOGIN, 0, $pos);
                                $LOGIN = substr($LOGIN, $pos + 1);
                        }
                        elseif($_SERVER["AUTH_TYPE"] == "Negotiate" && (($pos = strpos($LOGIN, "@")) !== false))
                        {
                                $LOGIN = substr($LOGIN, 0, $pos);
                                $DOMAIN = substr($LOGIN, $pos + 1);
                        }

                        $arFilterServer = array('ACTIVE' => 'Y');

                        if(strlen($DOMAIN) > 0)
                        {
                                $arFilterServer['CODE'] = $DOMAIN;
                        }
                        else
                        {
                                $DEF_DOMAIN_ID = intval(COption::GetOptionInt('ldap', 'ntlm_default_server', 0));
                                if($DEF_DOMAIN_ID > 0)
                                        $arFilterServer['ID'] = $DEF_DOMAIN_ID;
                                else
                                        return;
                        }

                        $db_ldap_serv = CLdapServer::GetList(Array(), $arFilterServer);

                        /*@var $xLDAP CLDAP*/
                        while($xLDAP = $db_ldap_serv->GetNextServer())
                        {
                                if($xLDAP->Connect())
                                {
                                        if($arLdapUser = $xLDAP->FindUser($LOGIN))
                                        {
                                                // не устанавливаем руководителя отдела, чтобы не сбивать структуру, устаноленную агентом
                                                $arLdapUser['UF_DEPARTMENT']['IS_HEAD'] = self::isOneUser($LOGIN);

                                                self::log('NTLMAuth', ['arLdapUser' => $arLdapUser], $arLog, 'before set user');

                                                $ID = $xLDAP->SetUser($arLdapUser, (COption::GetOptionString("ldap", "add_user_when_auth", "Y")=="Y"));

                                                self::log('NTLMAuth', ['ID' => $ID], $arLog, 'after set user');

                                                if($ID > 0)
                                                {
                                                        $USER->Authorize($ID);
                                                        $xLDAP->Disconnect();
                                                        return;
                                                }
                                        }

                                        $xLDAP->Disconnect();
                                }
                        }
                }
        }
}
