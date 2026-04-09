<?php

/**
 * Mageaustralia_Fpc — Full Page Cache
 *
 * Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * Licensed under the Open Software License v3.0 (OSL-3.0)
 */

declare(strict_types=1);

/**
 * AJAX Observer — intercepts controller responses when isEasyAjax is present.
 *
 * Absorbed from VF_EasyAjax. When a request includes the isEasyAjax parameter,
 * the normal HTML response is replaced with a JSON response containing:
 * - Session messages (success/error/notice)
 * - Requested block HTML
 * - Cart data (quantity, form key)
 * - Redirect URL (if the action tried to redirect)
 */
class Mageaustralia_Fpc_Model_Ajax_Observer
{
    /**
     * Intercept controller postdispatch and convert response to JSON if isEasyAjax.
     *
     * Event: controller_action_postdispatch
     */
    public function onPostdispatch(Varien_Event_Observer $observer): void
    {
        $action = $observer->getEvent()->getControllerAction();
        if (!$action) {
            return;
        }

        $request = $action->getRequest();

        // Only intercept when isEasyAjax param is present
        if (!$request->getParam('isEasyAjax')) {
            return;
        }

        $response = $action->getResponse();

        /** @var Mageaustralia_Fpc_Model_Ajax_Core $ajaxCore */
        $ajaxCore = Mage::getModel('mageaustralia_fpc/ajax_core');

        /** @var Mageaustralia_Fpc_Model_Ajax_Message_Storage $messageStorage */
        $messageStorage = Mage::getModel('mageaustralia_fpc/ajax_message_storage');

        // Collect session messages
        $messages = $messageStorage->extractAll();

        // Render requested blocks
        $blockNames = array_filter(
            explode(',', (string) $request->getParam('blocks', '')),
        );
        $blocks = $ajaxCore->loadContent($blockNames);

        // Check for redirects
        $extra = [
            'cart_qty' => $ajaxCore->getCartQty(),
            'form_key' => $ajaxCore->getFormKey(),
        ];

        // Detect redirect
        $headers = $response->getHeaders();
        foreach ($headers as $header) {
            if (isset($header['name']) && strtolower($header['name']) === 'location') {
                $extra['redirect'] = $header['value'];
                $response->clearHeader('Location');
                break;
            }
        }

        // Check if original action set an error via response code
        $httpCode = $response->getHttpResponseCode();
        $success = ($httpCode >= 200 && $httpCode < 400);

        $result = $ajaxCore->buildResponse($blocks, $messages, $extra);
        $result['success'] = $success;

        // Replace response body with JSON
        $response->clearBody();
        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', 'application/json', true);
        $response->setBody(json_encode($result, JSON_THROW_ON_ERROR));
    }
}
