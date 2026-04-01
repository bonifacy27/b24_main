<?php
error_reporting(6135);
  ini_set("display_errors",0);
$ar_errors = array();

if (isset ($_POST["BaseDN"]))
{
	if ($ldapconn = ldapconnect(false))
	{
		$sr = ldap_read($ldapconn, '', 'objectClass=*', Array('namingcontexts'), 0);
		$entry = ldap_first_entry($ldapconn, $sr);
		$attributes = ldap_get_attributes($ldapconn, $entry);
		$values = ldap_get_values_len($ldapconn, $entry, 'namingcontexts');
		unset($values["count"]);
		$_POST["dn_list"] = $values;
	}
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
<title>Тестирование выборок с AD сервера</title>
<meta http-equiv="Content-Type" content="text/html; charset=Windows-1251">
</head>
<body link="#1B75BC" alink="#1B75BC" vlink="#1B75BC">
<script type="text/javascript">
function check(a_filter)
{
	var filter = document.getElementById("ad_filter");
	var fields = document.getElementById("ad_fields");
	if (filter && fields)
	{
		var str_filter;
		var str_fields;
		switch(a_filter.id)
		{
			case "a_user":
				str_filter = "(&(objectClass=user)(objectCategory=PERSON))";
				str_fields = "sn,givenname,samaccountname,dn,manager,memberof,department";
				break;
			case "a_group":
				str_filter = "(objectCategory=group)";
				str_fields = "dn,sAMAccountName";
				break;
			default:
				str_filter = "";
				str_fields = "";
		}
		filter.value = str_filter;
		fields.value = str_fields;
	}
}
function showpass()
{
	var fl = document.getElementById('ad_pass');

	if (fl.type == 'text')
		fl.type = 'password';
	else
		fl.type = 'text';
}
</script>
<style type="text/css">
a {text-decoration: none;}
.marker {background-color: #DED6C9;}
/*input, select {border-style:solid ;border-width: 1px ;border-color:black;}*/
</style>
<div style="margin-top:50px; margin-bottom:10px; margin-right:10px; margin-left:200px;">
<form action='<?=$_SERVER["PHP_SELF"]?>' method="post">
	<table border='0' style="background-color:#DED6C9;color: #1B75BC;padding:10px;font-family:verdana;">
	<tr>
		<td>Хост</td>
		<td colspan="2"><input type="text" name="ad_host" value="<?=$_POST["ad_host"]?>">
		Порт
		<input type="text" name="ad_port" size="4" value="<?=($_POST["ad_port"])?$_POST["ad_port"]:"389"?>"></td>
	</tr>
	<tr>
		<td>Логин</td>
		<td colspan="2"><input type="text" name="ad_login" value="<?=$_POST["ad_login"]?>"></td>
	</tr>
	<tr>
		<td>Пароль</td>
		<td colspan="2">
			<input type="<?=(isset($_POST["check_pass"]))?"password":"text"?>" name="ad_pass" id="ad_pass" value="<?=$_POST["ad_pass"]?>">
			<input type="checkbox" onchange="showpass();" name="check_pass" <?if(isset($_POST["check_pass"])) echo 'checked';?>>Скрыть
		</td>
	</tr>
	<tr>
		<td>BaseDN</td>
		<td><input type="text" name="ad_basedn" id="ad_basedn" size="50" value="<?=$_POST["ad_basedn"]?>"></td>
		<td>
			<input type="hidden" name="temp_base" value='<?=(!isset($_POST["dn_list"]))? $_POST["temp_base"]: serialize($_POST["dn_list"])?>' />
			<input type="submit" name="BaseDN" value="Получить BaseDN" />
			<select onchange="document.getElementById('ad_basedn').value=this.value">
				<option value=""></option>
				<?if (!isset($_POST["dn_list"]))
						$_POST["dn_list"] = unserialize($_POST["temp_base"]);
				?>
				<?if (is_array($_POST["dn_list"])):?>
					<?foreach ($_POST["dn_list"] as $dn):?>
						<option value="<?=$dn?>" title="<?=$dn?>"><?=$dn?></option>
					<?endforeach;?>
				<?endif;?>
			</select>

		</td> 
	</tr>
	<tr>
		<td>Строка фильтра</td>
		<td><input type="text" name="ad_filter" id="ad_filter" size="50" value="<?=$_POST["ad_filter"]?>"></td>
		<td>
			<a href="javascript:void(0);" onclick="javascript:check(this);" id="a_user">|&nbsp;Пользователи&nbsp;|&nbsp;</a>
			<a href="javascript:void(0);" onclick="javascript:check(this);" id="a_group">Группы&nbsp;|&nbsp;</a>
			<a href="javascript:void(0);" onclick="javascript:check(this);" id="a_other">Свой&nbsp;|&nbsp;</a>
		</td>
	</tr>
	<tr>
		<td>Выводимые поля</td>
		<td>
			<input type="text" name="ad_fields" id="ad_fields" size="50" value="<?=$_POST["ad_fields"]?>">
		</td>
		<td>
			<a href="javascript:void(0);" onclick="document.getElementById('ad_fields').value=''">Очистить</a>
		</td>
	</tr>
	<tr><td><input type="submit" name="exec" value="Запросить"></td></tr>
	</table>
	
</form>
</div>
<?

if (isset ($_POST["exec"]))
{
	// connect to ldap server
	if ($ldapconn = ldapconnect())
	{
		ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
		ldap_set_option($ldapconn, LDAP_OPT_SIZELIMIT, 0);
		ldap_set_option($ldapconn, LDAP_OPT_TIMELIMIT, 5);
		ldap_set_option($ldapconn, LDAP_OPT_NETWORK_TIMEOUT , 5);

		if (!is_array($ldapconn))
				$ldapconn = array($ldapconn);
		$BaseDN = trim ($_POST["ad_basedn"]);
		if (strlen($BaseDN) == 0)
			$ar_errors[] = "Не указан BaseDN";
		$filter = trim ($_POST["ad_filter"]);

		$fields = explode(",", $_POST["ad_fields"]);
		$fields_new = array();

		if (!empty($_POST["ad_fields"]))
		{
			$fields = explode(",",$_POST["ad_fields"]);
			foreach ($fields as $f)
				$fields_new[] = trim($f);
		}

		$sr = ldap_search($ldapconn, $BaseDN, $filter, $fields_new);
		if ($sr)
		{
			$entries = ldap_get_entries($ldapconn[0], $sr[0]);
			if ($entries["count"] != 0)
			{
				ShowMessage ("Найдено ".$entries["count"]." записей");
				unset($entries["count"]);
				echo "<table style='background-color:#FAF3D2;margin:10px;'>";

				if (count($fields_new) > 0)
				{
					echo "<tr><td style='font-weight:bold;'>#</td>";
					foreach($fields_new as $field)
					{
						echo "<td style='font-weight:bold;'>".$field."</td>";
					}
					echo "</tr>";
					$imarker = 0;
					foreach($entries as $key => $row)
					{
						echo "<tr class='".((++$imarker%2)?'marker':'')."'>";
						echo "<td>".(++$key)."</td>";
						foreach($fields_new as $field)
						{
							$val=""; $result = array();

							$field = strtolower($field);
							if (isset($row[$field]))
							{
								$val=$row[$field];
								if (is_array($val))
								{
									for ($i=0;$i<$val["count"]; $i++)
										$result[]=$val[$i];
								}
								else
									$result[]=$val;
							}
							if (count($result)>0)
							{
								echo "<td>";
								foreach($result as $res)
									echo $res."<br>";
								echo "</td>";
							}
							else
								echo "<td>&nbsp;</td>";
						}
						echo "</tr>";
					}
				}
				else
				{
					$bhead = true;
					$result = array();
					$ar_column = array();
					foreach($entries as $keyi => $row)
					{
						$tmp = array();
						for ($i=0;$i<$row["count"]; $i++)
						{
							$ar_column[] = $row[$i];
							$tmp[$row[$i]] = $row[$row[$i]];
							unset($tmp[$row[$i]][count]);
						}
						$result[] = $tmp;
					}
					$ar_column= array_unique($ar_column);

					foreach ($result as $res)
					{
						echo "<tr class='".((++$imarker%2)?'marker':'')."'>";
						foreach($ar_column as $col)
						{
							if ($bhead)
							{
								echo "<td style='font-weight:bold;'>".$col."</td>";
							}
							else
							{
								$val = "";
								if (isset($res[$col]))
									$val = $res[$col];
								if (count($val)>0)
								{
									echo "<td>";
									foreach($val as $v)
										echo $v."<br>";
									echo "</td>";
								}
								else
									echo "<td>&nbsp;</td>";
							}
						}
						echo "</tr>";
						$bhead = false;
						
					}

					//$ar_errors[] = "Пустой список выводимых полей";
				}
				echo "</table>";
			}
			else
				$ar_errors[] = "Пустой результат";
		}
		else
		{
			
			if ($sr["count"] == 0)
				$ar_errors[] = "Неправильный фильтр";
		}
	}
}

ShowError();

function ldapconnect($show_ok = true)
{
	global $ar_errors;
	$ldaphost = trim($_POST["ad_host"]);
	$ldapport = trim($_POST["ad_port"]);
	if ($ldapport !== "")
		$ldapport = "389";

	$ldaplogin  = trim($_POST["ad_login"]);
	$ldappass = trim($_POST["ad_pass"]);

	$ldapconn = ldap_connect($ldaphost, $ldapport);
	if ($ldapconn)
	{
		$ldapbind = ldap_bind($ldapconn, $ldaplogin, $ldappass);
		if ($ldapbind)
			return $ldapconn;
		else
			$ar_errors[] = "Ошибка авторизации";
	}else
		$ar_errors[] = "Нет соединения с сервером";

	return false;
}

function ShowError ()
{
	global $ar_errors;
	if (is_array($ar_errors))
		foreach($ar_errors as $error)
			echo "<span style='color:red;border-style:solid;border-width:1px;padding:4px;font-family:verdana;margin-left:10px;'>".$error."</span><br>";
}
function ShowMessage ($mess)
{
	echo "<span style='color:green;border-style:solid;border-width:1px;padding:4px;font-family:verdana;margin-left:10px;'>".$mess."</span><br>";
}
?>

</body>
</html>
