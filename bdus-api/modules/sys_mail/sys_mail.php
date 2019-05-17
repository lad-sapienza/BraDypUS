<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 28, 2012
 */

class sys_mail_ctrl extends Controller
{

	public function send()
	{
		$to = '';
		$subject = $this->post['subject'];
		$body = $this->post['body'] . tr::sget('automatic_email_signature', strtoupper(APP));

		$headers = 'From: ' . $this->post['from']  . "\r\n" . 'Reply-To: ' . $this->post['from'] . "\r\n";

		$users_email = DB::start()->query('SELECT * FROM `' . PREFIX . 'users` WHERE `privilege` IN (' . implode(', ', $this->post['to']) . ')');

		if ($users_email)
		{
			foreach ($users_email as $usr)
			{
				$res = mail($usr['email'], $subject, $body, $headers);

				$res ? $ok[] = $usr['name'] . ' <' . $usr['email'] . '>' : $no[] = $usr['name'] . ' <' . $usr['email'] . '>';
			}
		}
		else
		{
			utils::alert_div('no_user_with_selected_privilege', true);
			return;
		}

		if (count($ok) > 0)
		{
			$ret_text = '<p>' . tr::sget('ok_mail_sent_to_users', '<ol><li>' . implode('</li><li>', $ok) . '</li></ol>') . '</p>';
		}
		if (count($no) > 0)
		{
			$ret_text = '<p>' . tr::sget('error_mail_sent_to_users', '<ol><li>' . implode('</li><li>', $no) . '</li></ol>') . '</p>';
		}

		echo $ret_text;

	}


	public function showForm()
	{

		echo $this->render('sys_mail', 'form', array(
				'tr' => new tr(),
				'adm_email' => APP . '_admin@bradypus.net',
				'user_email' => $_SESSION['user']['email'],
				'privileges' => utils::privilege('all', true)
				));
	}
}
