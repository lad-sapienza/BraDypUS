<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			All rights reserved
 * @since			Apr 14, 2012
 */

if (!$_GET['tb'])
{
	return;
}
?>

<ul>

	<li <?php echo utils::canUser('add_new') ? 'onclick="api.record.add(\'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-file-o"></i><br />
		<?php tr::show('new'); ?>
	</li>
	<li <?php echo utils::canUser('add_new') ? 'onclick="api.record.add(\'' . PREFIX . '__files\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-picture-o"></i><br />
		<?php tr::show('new_file'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'mostRecent\', \'' . $_GET['tb'] . '\', ' . ( pref::get('most_recent_no') ? pref::get('most_recent_no') : 10) . ']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-list-alt"></i><br />
		<?php tr::show('most_recent_records'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'all\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-table"></i><br />
		<?php tr::show('show_all'); ?>
	</li>
	
	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'advanced\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-search"></i><br />
		<?php tr::show('advanced_search'); ?>
	</li>

	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'search\', [\'sqlExpert\', \'' . $_GET['tb'] . '\']);"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-search-plus"></i><br />
		<?php tr::show('sql_expert_search'); ?>
	</li>

	<li style="width:200px;">
		<?php tr::show('fast_search');?><br />
		<input type="text" style="width: 90%;" placeholder="<?php tr::show('fast_search');?>" class="fast_search" data-table="<?php echo $_GET['tb']; ?>" />
	</li>
	
	<li <?php echo utils::canUser('edit') ? 'onclick="api.query.Export(\'1\', \'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		 <i class="fa fa-external-link"></i><br />
		<?php tr::show('export'); ?>
	</li>
	
	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'geoface\', \'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-map-marker"></i><br />
		<?php tr::show('GeoFace'); ?>
	</li>
	<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'geoface2\', \'' . $_GET['tb'] . '\');"' : 'class="state-disabled"'; ?>>
		<i class="fa fa-map-marker"></i><br />
		<?php tr::show('GeoFace'); ?>2
	</li>
	
</ul>