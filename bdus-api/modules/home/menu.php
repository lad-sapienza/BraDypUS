<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			01/apr/2011
 */

if (!utils::canUser('enter')) {
	echo '<h2>' . tr::get('not_enough_privilege') . '</h2>';
	return;
}
?>

<div class="visible-xs">
	<div class="jumbotron">
		<h2><?php echo strtoupper(APP) ?></h2>
		<p><?php echo cfg::main('definition')?></p>
	</div>
</div>
<?php if (cfg::main('status') === 'frozen'): ?>
  <div class="bg-danger lead text-danger" style="padding: 10px;"><i class="fa fa-info-circle"></i> <?= tr::get('app_is_frozen'); ?></div>
      <?php endif; ?>
<div class="row">
	<div class="col-md-9">
		<ul class="home clearfix">
			<li><h2><?php echo tr::get('data_mng')?></h2></li>
			<li class="tb">
				<div class="clearfix">
					<div class="pull-left">
						<select class="tb" style="font-size: 20px; height:38px; margin: 20px 0; width:250px;">
						<?php
							$all_tb = cfg::getNonPlg();

							foreach ( $all_tb as $tb_id => $tb_label ) {

								if (!cfg::tbEl($tb_id, 'is_plugin')) {
									echo  '<option value="' . $tb_id . '">' . $tb_label . '</option>';
								}
							}
						?>
						</select>
					</div>
					<div class="pull-right">
						<input type="search" id="find-fn" placeholder="<?= tr::get('search-fn'); ?>">
					</div>
				</div>
			</li>

			<li class="tb_opt"></li>

			<li><h2><?php echo tr::get('options'); ?></h2>
				<ul class="searcheable-list">

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'saved_queries\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-bookmark-o"></i><br />
						<?php echo tr::get('saved_queries'); ?>
					</li>

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'preferences\')"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-lightbulb-o"></i><br />
						<?php echo tr::get('user_preferences'); ?>
					</li>

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'user\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-group"></i><br />
						<?php echo tr::get('user_mng'); ?>
					</li>

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'myExport\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-random"></i><br />
						<?php echo tr::get('available_exports'); ?>
					</li>

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'chart\')"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-bar-chart-o"></i><br />
						<?php echo tr::get('saved_charts'); ?>
					</li>

					<li <?php echo utils::canUser('edit') ? 'onclick="core.runMod(\'backup\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-suitcase"></i><br />
						<?php echo tr::get('backup'); ?>
					</li>

					<li <?php echo utils::canUser('edit') ? 'onclick="core.runMod(\'search_replace\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-exchange"></i><br />
						<?php echo tr::get('find_replace'); ?>
					</li>

					<li <?php echo utils::canUser('edit') ? 'onclick="core.runMod(\'multiupload\');"' : 'class="state-disabled"'; ?>>
						<i class="glyphicon glyphicon-upload"></i><br />
						<?php echo tr::get('multiupload'); ?>
					</li>

          <li <?php echo utils::canUser('edit') ? 'onclick="core.runMod(\'import_geodata\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-cloud-upload"></i><br />
						<?php echo tr::get('import_geodata'); ?>
					</li>

					<li <?php echo utils::canUser('admin') ? 'onclick="core.runMod(\'vocabularies\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-quote-left"></i><br />
						<?php echo tr::get('vocabulary_mng'); ?>
					</li>

          <li <?php echo utils::canUser('admin') ? 'onclick="core.runMod(\'frontpage_editor\')"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-edit"></i><br />
						<?php echo tr::get('front_page_editor'); ?>
					</li>

					<li <?php echo utils::canUser('admin') ? 'onclick="core.runMod(\'sys_mail\')"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-envelope-o"></i><br />
						<?php echo tr::get('system_email'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'myHistory\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-book"></i><br />
						<?php echo tr::get('history'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'myImport\');"' : 'class="state-disabled"'; ?>>
						<i class=" fa fa-cloud-upload"></i><br />
						<?php echo tr::get('import'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'translate\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-comment"></i><br />
						<?php echo tr::get('system_translate'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'app_data_editor\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-wrench"></i><br />
						<?php echo tr::get('app_data_editor'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'tbs_editor\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-cog"></i><br />
						<?php echo tr::get('edit_tbs_data'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'flds_editor\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-cogs"></i><br />
						<?php echo tr::get('edit_flds_data'); ?>
					</li>

		<?php if(!utils::is_online()) :?>
					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'info\', \'getIP\')"' : 'class="state-disabled"'; ?>>
            <i class="fa fa-code"></i><br />
						<?php echo tr::get('ip'); ?>
					</li>
		<?php endif; ?>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'free_sql\');"' : 'class="state-disabled"'; ?>>
						<i class="glyphicon glyphicon-console"></i><br />
						<?php echo tr::get('run_free_sql'); ?>
					</li>

					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.runMod(\'empty_cache\');"' : 'class="state-disabled"'; ?>>
						<i class="glyphicon glyphicon-trash"></i><br />
						<?php echo tr::get('empty_cache'); ?>
					</li>

					<?php if (utils::canUser('super_admin')): ?>
					<li <?php echo utils::canUser('super_admin') ? 'onclick="core.open({obj: \'test_ctrl\', method: \'test\', title: \'test\'});"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-flask"></i><br />
						TEST
					</li>
					<?php endif; ?>

					<li <?php echo utils::canUser('read') ? 'onclick="core.runMod(\'debug\');"' : 'class="state-disabled"'; ?>>
						<i class="fa fa-bug"></i><br />
						<?php echo tr::get('debug'); ?>
					</li>

				</ul>
			</li>

			<li><h2><?php echo tr::get('other'); ?></h2>
				<ul class="searcheable-list">
					<li onclick="window.open('http://bradypus.net');">
						<i class="fa fa-globe"></i><br />
						<?php echo tr::get('bdus_web'); ?>
					</li>

					<li onclick="core.runMod('info', 'copyright');">
						<i class="fa fa-info-circle"></i><br />
						<?php echo tr::get('info'); ?>
					</li>

					<li onclick="api.reloadApp(1);">
						<i class="fa fa-refresh"></i><br />
						<?php echo tr::get('restart'); ?>
					</li>
					<li onclick="api.logOut();">
						<i class="fa fa-power-off"></i><br />
						<?php echo tr::get('close_application'); ?>
					</li>
				</ul>
			</li>
		</ul>
	</div>

	<div class="col-md-3 hidden-xs" style="padding:10px; text-align:right;">
		<?php
		if (file_exists(PROJ_DIR . 'welcome.html')) {
			require_once PROJ_DIR . 'welcome.html';
		}
		?>
		<hr style="margin-top: 50px;" />
		This database is powered by <br />
		<a href="http://bradypus.net" title="bradypus.net" target="_blank">
		<span style="font-size:1.5em">BraDypUS</span> <br />COMMUNICATING CULTURAL HERITAGE</a><br />
		<a href="http://twitter.com/TheBraDypUS" target="_blank">Follow us on Twitter</a>, <a href="https://twitter.com/search/realtime?q=bdusdb" target="_blank">#bdusdb</a>
		and <a href="http://www.facebook.com/pages/BraDypUS/214225491883" target="_blank">Facebook</a>
	</div>
</div>
