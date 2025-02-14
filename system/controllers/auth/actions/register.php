<?php
class actionAuthRegister extends cmsAction {

    public function run(){

        if ($this->cms_user->is_logged && !$this->cms_user->is_admin) { $this->redirectToHome(); }

        // если аккаунт не подтверждён и время не вышло
        // редиректим на верификацию
        $reg_email = cmsUser::getCookie('reg_email');
        if($reg_email && $this->validate_email($reg_email) === true){

            cmsUser::addSessionMessage(sprintf(LANG_REG_SUCCESS_NEED_VERIFY, $reg_email), 'info');

            $this->redirectToAction('verify');

        }

        list($form, $fieldsets) = $this->getRegistrationForm();

        $user = array();

        if ($this->request->hasInQuery('inv')){
            $user['inv'] = $this->request->get('inv','');
        }

        if ($this->request->has('submit')){

            if (!$this->options['is_reg_enabled']){
                cmsCore::error404();
            }

            //
            // Парсим и валидируем форму
            //
            $user = $form->parse($this->request, true);

            $user['groups'] = array();

            if (!empty($this->options['def_groups'])){
                $user['groups'] = $this->options['def_groups'];
            }

            if (isset($user['group_id'])) {
                if (!in_array($user['group_id'], $user['groups'])){
                    $user['groups'][] = $user['group_id'];
                }
            }

            //
            // убираем поля которые не относятся к выбранной пользователем группе
            //
            foreach($fieldsets as $fieldset){
                foreach($fieldset['fields'] as $field){

                    if (empty($field['groups_add'])) { continue; }
                    if (in_array(0, $field['groups_add'])) { continue; }

                    if (!in_array($user['group_id'], $field['groups_add'])){
                        $form->disableField($field['name']);
                        unset($user[$field['name']]);
                    }

                }
            }

            $errors = $form->validate($this,  $user);

            if (!$errors){

                // если поле nickname убрано из обязательных
                if(!isset($user['nickname'])){
                    $user['nickname'] = strstr($user['email'], '@', true);
                }

                //
                // проверяем код приглашения
                //
                if ($this->options['is_reg_invites'] || $this->request->has('inv')){
                    $invite = $this->model->getInviteByCode($user['inv']);
                    if (!$invite) {
                        $errors['inv'] = LANG_REG_WRONG_INVITE_CODE;
                    } else {
                        if ($this->options['is_invites_strict'] &&
                                $this->options['is_reg_invites'] && ($invite['email'] != $user['email'])) {
                            $errors['inv'] = LANG_REG_WRONG_INVITE_CODE_EMAIL;
                        } else {
                            $user['inviter_id'] = $invite['user_id'];
                        }
                    }
                }

                //
                // проверяем допустимость e-mail, имени и IP
                //
                if (!$this->isEmailAllowed($user['email'])){
                    $errors['email'] = sprintf(LANG_AUTH_RESTRICTED_EMAIL, $user['email']);
                }

                if (!$this->isNameAllowed($user['nickname'])){
                    $errors['nickname'] = sprintf(LANG_AUTH_RESTRICTED_NAME, $user['nickname']);
                }

                if (!$this->isIPAllowed(cmsUser::get('ip'))){
                    cmsUser::addSessionMessage(sprintf(LANG_AUTH_RESTRICTED_IP, cmsUser::get('ip')), 'error');
                    $errors = true;
                }

            }

            list($errors, $user) = cmsEventsManager::hook('registration_validation', array($errors, $user));

            if (!$errors){

                //
                // Блокируем пользователя, если включена верификация e-mail
                //
                if ($this->options['verify_email']){
                    $user = array_merge($user, array(
                        'is_locked' => true,
                        'lock_reason' => LANG_REG_CFG_VERIFY_LOCK_REASON,
                        'pass_token' => string_random(32, $user['email']),
                        'date_token' => ''
                    ));
                }

                $result = $this->model_users->addUser($user);

                if ($result['success']){

					$user['id'] = $result['id'];

                    // если использовали код приглашения
                    if(!empty($invite['id'])){

                        // для декремента счётчика инвайтов, если приглашение по ссылке
                        if(empty($invite['email'])){
                            $this->model->markInviteSended($invite['id'], $invite['user_id'], $user['email']);
                        }

                        // удаляем инвайт, раз им воспользовались
                        $this->model->deleteInvite($invite['id']);

                        // уведомляем того, чей инвайт
                        $this->model_messages->addNotice(array($invite['user_id']), array(
                            'content' => sprintf(LANG_AUTH_INVITE_NOTIFY, href_to_profile($user), $user['nickname'])
                        ));

                    }

                    cmsUser::addSessionMessage(LANG_REG_SUCCESS, 'success');

                    cmsUser::setUPS('first_auth', 1, $user['id']);

                    // отправляем письмо верификации e-mail
                    if ($this->options['verify_email']){

                        $verify_exp = empty($this->options['verify_exp']) ? 48 : $this->options['verify_exp'];

                        $to = array('email' => $user['email'], 'name' => $user['nickname']);
                        $letter = array('name' => 'reg_verify');

                        $this->controller_messages->sendEmail($to, $letter, array(
                            'nickname'    => $user['nickname'],
                            'page_url'    => href_to_abs('auth', 'verify', $user['pass_token']),
                            'pass_token'  => $user['pass_token'],
                            'valid_until' => html_date(date('d.m.Y H:i', time() + ($verify_exp * 3600)), true)
                        ));

                        cmsUser::addSessionMessage(sprintf(LANG_REG_SUCCESS_NEED_VERIFY, $user['email']), 'info');

                        cmsUser::setCookie('reg_email', $user['email'], $verify_exp*3600);

                        // редиректим сразу на форму подтверждения регистрации
                        $this->redirectToAction('verify');

                    } else {

						cmsEventsManager::hook('user_registered', $user);

                        // авторизуем пользователя автоматически
                        if ($this->options['reg_auto_auth']){

                            $logged_id = cmsUser::login($user['email'], $user['password1']);

                            if ($logged_id){

                                cmsUser::deleteUPS('first_auth', $logged_id);

                                cmsEventsManager::hook('auth_login', $logged_id);

                            }

                        }

					}

                    $back_url = cmsUser::sessionGet('auth_back_url') ?
                                cmsUser::sessionGet('auth_back_url', true) :
                                false;

                    if ($back_url){
                        $this->redirect($back_url);
                    } else {
                        $this->redirect($this->getAuthRedirectUrl($this->options['first_auth_redirect']));
                    }

                } else {
                    $errors = $result['errors'];
                }

            }

            if ($errors){
                cmsUser::addSessionMessage(LANG_FORM_ERRORS, 'error');
            }

        }

        // запоминаем откуда пришли на регистрацию
        $back_url = $this->request->get('back', '');
        if(empty($errors) && ($back_url || $this->options['first_auth_redirect'] == 'none')){
            cmsUser::sessionSet('auth_back_url', ($back_url ? $back_url :$this->getBackURL()));
        }

        return $this->cms_template->render('registration', array(
            'user'         => $user,
            'form'         => $form,
            'errors'       => isset($errors) ? $errors : false
        ));

    }

}
