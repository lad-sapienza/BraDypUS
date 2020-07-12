<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 14, 2012
 */

if (!$_GET['tb']) {
	return;
}
?>

<ul class="searcheable-list">

	<li <?php echo utils::canUser('add_new') ? 'onclick="api.record.add(\'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-file-o"></i><br />
		<?php echo tr::get('new'); ?>
	</li>
	<li <?php echo utils::canUser('add_new') ? 'onclick="api.record.add(\'' . PREFIX . 'files\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-picture-o"></i><br />
		<?php echo tr::get('new_file'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'mostRecent\', \'' . $_GET['tb'] . '\', ' . ( pref::get('most_recent_no') ? pref::get('most_recent_no') : 10) . ']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-list-alt"></i><br />
		<?php echo tr::get('most_recent_records'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'all\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-table"></i><br />
		<?php echo tr::get('show_all'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'advanced\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-search"></i><br />
		<?php echo tr::get('advanced_search'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'sqlExpert\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-search-plus"></i><br />
		<?php echo tr::get('sql_expert_search'); ?>
	</li>

	<li style="width:200px;">
		<?php echo tr::get('fast_search');?><br />
		<input type="text" style="width: 90%;" placeholder="<?php echo tr::get('fast_search');?>" class="fast_search" data-table="<?php echo $_GET['tb']; ?>" />
	</li>

	<li <?php echo utils::canUser('edit') ? 'onclick="api.query.Export(\'1\', \'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		 <i class="fa fa-external-link"></i><br />
		<?php echo tr::get('export'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'geoface\', \'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-map-marker"></i><br />
		<?php echo tr::get('GeoFace'); ?>
	</li>

</ul>
