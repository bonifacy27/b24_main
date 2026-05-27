<?php
/**
 * /bitrix/admin/trs_skud_users_reverse.php
 * v1.3 — поиск по LastName + FirstName, фикс пагинации
 */

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use TRS\Reverse;
use TRS\Reverse2;
use TRS\User;

global $APPLICATION, $USER;

$APPLICATION->SetTitle("СКУД: пользователи Reverse / Reverse2");

if (!$USER->IsAdmin()) {
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_after.php");
	echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">Доступ запрещён</div></div>';
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_admin.php");
	die();
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_after.php");

if (!Loader::includeModule('tricolor.trs')) {
	echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">Не подключился модуль tricolor.trs</div></div>';
	require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_admin.php");
	die();
}

/**
 * Получение пользователей из конкретного СКУД-класса.
 * Возвращает массив пользователей (массив из Data).
 */
function trsGetSkudUsers($skudObj): array
{
	$users = [];
	try {
		$skudObj->openSocket();
		$res = $skudObj->getUserList();
		$skudObj->closeSocket();

		if (is_array($res) && isset($res['Data']) && is_array($res['Data'])) {
			$users = $res['Data'];
		}
	} catch (\Throwable $e) {
		try { $skudObj->closeSocket(); } catch (\Throwable $e2) {}
		throw $e;
	}

	return $users;
}

/** safe get */
function v($arr, $key)
{
	return isset($arr[$key]) ? $arr[$key] : '';
}

/** mb lower helper */
function trsLower(string $s): string
{
	if (function_exists('mb_strtolower')) {
		return mb_strtolower($s, 'UTF-8');
	}
	return strtolower($s);
}

/**
 * build url with params (FIXED)
 * ВАЖНО: GetCurPageParam сам добавляет текущие параметры.
 * Поэтому мы:
 *  - добавляем только НЕпустые параметры в строку
 *  - удаляем из URL все ключи, которые переопределяем (чтобы не было page=1&page=2)
 */
function trsBuildUrl(array $params, array $exclude = []): string
{
	$add = [];
	$del = $exclude;

	foreach ($params as $k => $v) {
		$del[] = $k;

		// Пустые значения не добавляем — просто удаляем параметр из URL
		if ($v === null || $v === '' || (is_array($v) && empty($v))) {
			continue;
		}
		$add[$k] = $v;
	}

	$del = array_values(array_unique($del));

	return $GLOBALS['APPLICATION']->GetCurPageParam(
		http_build_query($add),
		$del
	);
}

try {
	// Параметры
	$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
	$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
	$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

	if ($page < 1) $page = 1;
	if ($perPage < 10) $perPage = 10;
	if ($perPage > 500) $perPage = 500;

	// 1) Reverse
	$reverse = new Reverse();
	$reverseUsers = trsGetSkudUsers($reverse);

	// 2) Reverse2
	$reverse2 = new Reverse2();
	$reverse2Users = trsGetSkudUsers($reverse2);

	// Объединим в один список
	$all = [];

	foreach ($reverseUsers as $u) {
		if (!is_array($u)) continue;
		$u['_SOURCE'] = 'Reverse';
		$all[] = $u;
	}

	foreach ($reverse2Users as $u) {
		if (!is_array($u)) continue;
		$u['_SOURCE'] = 'Reverse2';
		$all[] = $u;
	}

	$totalBeforeFilter = count($all);

	// Соберём “все ключи” (колонки) динамически
	$allKeys = ['Id', 'LastName', 'FirstName', 'SecondName'];
	foreach ($all as $u) {
		foreach (array_keys($u) as $k) {
			if ($k === '_SOURCE') continue;
			if (!in_array($k, $allKeys, true)) $allKeys[] = $k;
		}
	}

	// Фильтр по LastName или FirstName (по всем данным, до пагинации)
	if ($q !== '') {
		$qLower = trsLower($q);

		$all = array_values(array_filter($all, function($u) use ($qLower) {
			$ln = trsLower((string)v($u, 'LastName'));
			$fn = trsLower((string)v($u, 'FirstName'));

			// Ищем по подстроке: фамилия ИЛИ имя
			return ($ln !== '' && strpos($ln, $qLower) !== false)
				|| ($fn !== '' && strpos($fn, $qLower) !== false);
		}));
	}

	$totalAfterFilter = count($all);

	// Сопоставление с порталом (берем ID из текущего отфильтрованного массива)
	$reverseIds = [];
	$reverse2Ids = [];

	foreach ($all as $u) {
		$id = (string)v($u, 'Id');
		if ($id === '') continue;

		if ($u['_SOURCE'] === 'Reverse') $reverseIds[] = $id;
		if ($u['_SOURCE'] === 'Reverse2') $reverse2Ids[] = $id;
	}

	$reverseIds = array_values(array_unique($reverseIds));
	$reverse2Ids = array_values(array_unique($reverse2Ids));

	$portalByReverseId = [];
	if (!empty($reverseIds)) {
		$portalByReverseId = User::getList(
			[
				'ACTIVE' => 'Y',
				'UF_IDREVERSE' => $reverseIds
			],
			'UF_IDREVERSE'
		);
	}

	$portalByReverse2Id = [];
	if (!empty($reverse2Ids)) {
		$portalByReverse2Id = User::getList(
			[
				'ACTIVE' => 'Y',
				'UF_IDREVERSE2' => $reverse2Ids
			],
			'UF_IDREVERSE2'
		);
	}

	// Пагинация
	$totalPages = (int)ceil($totalAfterFilter / $perPage);
	if ($totalPages < 1) $totalPages = 1;
	if ($page > $totalPages) $page = $totalPages;

	$offset = ($page - 1) * $perPage;
	$pageItems = array_slice($all, $offset, $perPage);

	// Верхняя панель: поиск + на странице
	?>
	<form method="get" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPage())?>" style="margin-bottom: 12px;">
		<div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
			<div>
				<div style="margin-bottom:4px; color:#555;">Поиск по LastName / FirstName</div>
				<input type="text" name="q" value="<?=htmlspecialcharsbx($q)?>" size="30" placeholder="например: Иванов или Иван">
			</div>

			<div>
				<div style="margin-bottom:4px; color:#555;">На странице</div>
				<select name="per_page">
					<?php foreach ([10, 20, 50, 100, 200, 500] as $n): ?>
						<option value="<?=$n?>" <?=$perPage===$n?'selected':''?>><?=$n?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div>
				<input type="hidden" name="page" value="1">
				<input type="submit" class="adm-btn-save" value="Найти">
				<a class="adm-btn" href="<?=htmlspecialcharsbx(trsBuildUrl(['q' => '', 'page' => 1]))?>">Сбросить</a>
			</div>

			<div style="margin-left:auto; color:#555;">
				Всего: <b><?= (int)$totalBeforeFilter ?></b>
				<?php if ($q !== ''): ?>
					&nbsp;|&nbsp;Найдено: <b><?= (int)$totalAfterFilter ?></b>
				<?php endif; ?>
				&nbsp;|&nbsp;Страница: <b><?= (int)$page ?></b> / <b><?= (int)$totalPages ?></b>
			</div>
		</div>
	</form>
	<?php

	// Пейджер
	$renderPager = function() use ($page, $totalPages) {
		if ($totalPages <= 1) return;

		$from = max(1, $page - 5);
		$to = min($totalPages, $page + 5);

		echo '<div style="margin: 10px 0; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">';

		if ($page > 1) {
			echo '<a class="adm-btn" href="' . htmlspecialcharsbx(trsBuildUrl(['page' => 1])) . '">« Первая</a>';
			echo '<a class="adm-btn" href="' . htmlspecialcharsbx(trsBuildUrl(['page' => $page - 1])) . '">‹ Назад</a>';
		} else {
			echo '<span class="adm-btn" style="opacity:.4; pointer-events:none;">« Первая</span>';
			echo '<span class="adm-btn" style="opacity:.4; pointer-events:none;">‹ Назад</span>';
		}

		for ($p = $from; $p <= $to; $p++) {
			if ($p === $page) {
				echo '<span class="adm-btn adm-btn-save" style="pointer-events:none;">' . (int)$p . '</span>';
			} else {
				echo '<a class="adm-btn" href="' . htmlspecialcharsbx(trsBuildUrl(['page' => $p])) . '">' . (int)$p . '</a>';
			}
		}

		if ($page < $totalPages) {
			echo '<a class="adm-btn" href="' . htmlspecialcharsbx(trsBuildUrl(['page' => $page + 1])) . '">Вперёд ›</a>';
			echo '<a class="adm-btn" href="' . htmlspecialcharsbx(trsBuildUrl(['page' => $totalPages])) . '">Последняя »</a>';
		} else {
			echo '<span class="adm-btn" style="opacity:.4; pointer-events:none;">Вперёд ›</span>';
			echo '<span class="adm-btn" style="opacity:.4; pointer-events:none;">Последняя »</span>';
		}

		echo '</div>';
	};

	$renderPager();

	// Таблица
	echo '<table class="adm-list-table" style="width:100%">';
	echo '<thead><tr class="adm-list-table-header">';

	echo '<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Источник</div></td>';

	foreach ($allKeys as $k) {
		echo '<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">' . htmlspecialcharsbx($k) . '</div></td>';
	}

	echo '<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Пользователь портала</div></td>';
	echo '<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Дубли на портале</div></td>';

	echo '</tr></thead><tbody>';

	if (empty($pageItems)) {
		echo '<tr class="adm-list-table-row"><td class="adm-list-table-cell" colspan="' . (count($allKeys) + 3) . '">Ничего не найдено</td></tr>';
	} else {
		foreach ($pageItems as $u) {
			$source = (string)$u['_SOURCE'];
			$skudId = (string)v($u, 'Id');

			$portalMatches = [];
			if ($source === 'Reverse') {
				$portalMatches = $portalByReverseId[$skudId] ?? [];
			} else {
				$portalMatches = $portalByReverse2Id[$skudId] ?? [];
			}

			$mainPortal = '';
			$dupesHtml = '';

			if (!empty($portalMatches)) {
				$first = $portalMatches[0];
				$mainPortal = (int)$first['ID'] . ': '
					. trim($first['LAST_NAME'] . ' ' . $first['NAME'] . ' ' . $first['SECOND_NAME'])
					. (!empty($first['EMAIL']) ? ' (' . $first['EMAIL'] . ')' : '');

				if (count($portalMatches) > 1) {
					$tmp = [];
					foreach ($portalMatches as $pm) {
						$tmp[] = (int)$pm['ID'] . ': ' . trim($pm['LAST_NAME'] . ' ' . $pm['NAME'] . ' ' . $pm['SECOND_NAME']);
					}
					$dupesHtml = implode('<br>', array_map('htmlspecialcharsbx', $tmp));
				}
			}

			echo '<tr class="adm-list-table-row">';
			echo '<td class="adm-list-table-cell"><b>' . htmlspecialcharsbx($source) . '</b></td>';

			foreach ($allKeys as $k) {
				$val = v($u, $k);
				if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
				echo '<td class="adm-list-table-cell">' . htmlspecialcharsbx((string)$val) . '</td>';
			}

			echo '<td class="adm-list-table-cell">' . htmlspecialcharsbx($mainPortal) . '</td>';
			echo '<td class="adm-list-table-cell">' . ($dupesHtml !== '' ? $dupesHtml : '') . '</td>';
			echo '</tr>';
		}
	}

	echo '</tbody></table>';

	$renderPager();

} catch (\Throwable $e) {
	echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
		. htmlspecialcharsbx($e->getMessage())
		. '</div></div>';
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_admin.php");