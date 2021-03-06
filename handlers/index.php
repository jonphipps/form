<?php

/**
 * Render a form based on the form ID. Usage:
 *
 *     {! form/form-id !}
 *
 * From the URL:
 *
 *     /form/form-id
 */

$id = (isset ($this->params[0])) ? $this->params[0] : (isset ($data['id']) ? $data['id'] : false);
if (! $id) {
	// no form specified
	@error_log ('no form specified');
	return;
}

$f = new form\Form ((int) $id);
if ($f->error) {
	// form not found
	@error_log ('form not found');
	return;
}

if ($f->submit ()) {
	// handle form submission

	// unset csrf prevention token
	unset ($_POST['_token_']);

	// save the results
	$r = new form\Results (array (
		'form_id' => $id,
		'ts' => gmdate ('Y-m-d H:i:s'),
		'ip' => $_SERVER['REMOTE_ADDR']
	));
	$r->results = $_POST;
	$r->put ();

	// call any custom hooks
	$this->hook ('form/submitted', array (
		'form' => $id,
		'values' => $_POST
	));

	foreach ($_POST as $k => $v) {
		if (is_array ($v)) {
			$_POST[$k] = join (', ', $v);
		}
	}

	// if there's a redirect, we wait to exit after
	// all actions have been performed.
	$has_redirect = false;

	foreach ($f->actions as $action) {
		// handle action
		switch ($action->type) {
			case 'email':
				$f->send_email (
					$action->to,
					$f->title,
					$tpl->render ('form/email', array ('values' => $_POST)),
					array (conf ('General', 'email_from'), conf ('General', 'site_name'))
				);
				break;
			case 'cc_recipient':
				$send_to = $action->name_field
					? array ($_POST[$action->email_field], $_POST[$action->name_field])
					: $_POST[$action->email_field];

				$msg_body = $action->body_intro;
				if ($action->include_data == 'yes') {
					$labels = $f->labels ();
					$msg_body .= "\n\n";
					foreach ($_POST as $k => $v) {
						$msg_body .= '* ' . str_pad ($labels[$k], 32, ' ', STR_PAD_RIGHT) . ': ' . $v . "\n";
					}
					$msg_body .= "\n";
				}
				$msg_body .= $action->body_sig;

				$f->send_email (
					$send_to,
					$action->subject,
					$msg_body,
					$action->reply_from
				);
				break;
			case 'redirect':
				$this->redirect ($action->url, false);
				$has_redirect = true;
				break;
		}
	}

	if ($has_redirect) {
		$this->quit ();
	}

	if (! $this->internal) {
		$page->title = $f->response_title;
	}

	echo $f->response_body;
} else {
	// render the form
	if (! $this->interal) {
		$page->title = $f->title;
	}

	$o = $f->orig ();
	$o->failed = $f->failed;
	echo $tpl->render ('form/head', $o);

	foreach ($f->field_list as $field) {
		if ($field->type == 'range') {
			$page->add_script ('/apps/form/js/jquery.tools.min.js');
			$page->add_script ('<script>$(function(){$(":range").rangeinput({progress:true});});</script>');
			$page->add_script ('/apps/form/css/rangeinput.css');
		} elseif ($field->type == 'date') {
			$page->add_script ('/apps/form/js/jquery.tools.min.js');
			$page->add_script ('<script>$(function(){$(":date").dateinput({format:"yyyy-mm-dd"});});</script>');
			$page->add_script ('/apps/form/css/dateinput.css');
			if ($field->default_value == 'today') {
				$field->default_value = gmdate ('Y-m-d');
			}
		}
		echo $tpl->render ('form/field/' . $field->type, $field);
	}

	echo $tpl->render ('form/tail', $o);
}

?>