(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.drupalxAiChatbot = {
    attach: function (context, settings) {
      // Find all chatbot widgets on the page (though typically one per page).
      $(context).find('.drupalx-ai-chatbot-widget').once('drupalx-ai-chatbot').each(function () {
        var $widget = $(this);
        var $messagesContainer = $widget.find('.drupalx-ai-chatbot-messages');
        var $inputField = $widget.find('.drupalx-ai-chatbot-input input[type="text"]');
        var $sendButton = $widget.find('.drupalx-ai-chatbot-input button');
        var chatbotUrl = drupalSettings.drupalx_ai.chatbot_url;

        function addMessage(text, type) {
          var $message = $('<div class="message ' + type + '"></div>').text(text);
          $messagesContainer.append($message);
          $messagesContainer.scrollTop($messagesContainer[0].scrollHeight); // Scroll to bottom
        }

        function sendMessage() {
          var messageText = $inputField.val().trim();
          if (messageText === '') {
            return;
          }

          addMessage(messageText, 'user');
          $inputField.val('');
          $sendButton.prop('disabled', TRUE);

          // AJAX call to the chatbot controller.
          $.ajax({
            url: chatbotUrl,
            type: 'POST',
            data: JSON.stringify({ message: messageText }),
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            success: function (response) {
              if (response.reply) {
                addMessage(response.reply, 'bot');
              }
              else {
                addMessage('Sorry, I received an empty response.', 'bot');
              }
            },
            error: function (xhr, status, error) {
              var errorMessage = 'Error: Could not connect to the server.';
              if (xhr.responseJSON && xhr.responseJSON.reply) {
                errorMessage = 'Error: ' + xhr.responseJSON.reply;
              }
              else if (xhr.responseText) {
                try {
                  var errorData = JSON.parse(xhr.responseText);
                  if (errorData.message) {
                    errorMessage = 'Error: ' + errorData.message;
                  }
                }
                catch (e) {
                  // The responseText was not JSON.
                  errorMessage = 'Error: ' + xhr.responseText.substring(0, 100);
                }
              }
              addMessage(errorMessage, 'bot');
              console.error('Chatbot AJAX error:', status, error, xhr.responseText);
            },
            complete: function () {
              $sendButton.prop('disabled', FALSE);
              $inputField.focus();
            }
          });
        }

        $sendButton.on('click', sendMessage);

        $inputField.on('keypress', function (e) {
          if (e.which === 13) { // Enter key
            sendMessage();
            e.preventDefault();
          }
        });

        // Initial bot message (optional)
        // addMessage("Hello! How can I help you generate a landing page today?", "bot");
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
