(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.drupalxAiChatbot = {
    attach: function (context, settings) {
      // Find all chatbot containers on the page (though typically one per page).
      // Use Drupal.once() instead of the deprecated jQuery once plugin
      Drupal.once('drupalx-ai-chatbot', '.drupalx-ai-chatbot-container', context).forEach(function (container) {
        var $container = $(container);
        var $toggle = $container.find('.drupalx-ai-chatbot-toggle');
        var $closeBtn = $container.find('.drupalx-ai-chatbot-close');
        var $widget = $container.find('.drupalx-ai-chatbot-widget');
        var $messagesContainer = $container.find('.drupalx-ai-chatbot-messages');
        var $inputField = $container.find('.drupalx-ai-chatbot-input input[type="text"]');
        var $sendButton = $container.find('.drupalx-ai-chatbot-input button');
        var chatbotUrl = drupalSettings.drupalx_ai.chatbot_url;
        
        // Toggle chatbot visibility
        $toggle.on('click', function() {
          $widget.toggleClass('active');
          // Focus the input field when opening
          if ($widget.hasClass('active')) {
            $inputField.focus();
          }
        });
        
        // Close button functionality
        $closeBtn.on('click', function() {
          $widget.removeClass('active');
        });

        function addMessage(text, type) {
          // Process message text (handle basic markdown-like formatting)
          var processedText = text;
          
          // Create link elements for URLs
          processedText = processedText.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
          
          // Handle code blocks with backticks
          processedText = processedText.replace(/`([^`]+)`/g, '<code>$1</code>');
          
          // Handle bold with asterisks
          processedText = processedText.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
          
          // Handle lists with dashes
          processedText = processedText.replace(/^- (.+)$/gm, '• $1');
          
          var $message = $('<div class="message ' + type + '"></div>').html(processedText);
          $messagesContainer.append($message);
          
          // Clear any floating elements before scrolling
          var $clearFloat = $('<div style="clear:both;"></div>');
          $messagesContainer.append($clearFloat);
          
          // Scroll to bottom smoothly
          $messagesContainer.animate({
            scrollTop: $messagesContainer[0].scrollHeight
          }, 300);
        }

        function sendMessage() {
          var messageText = $inputField.val().trim();
          if (messageText === '') {
            return;
          }

          addMessage(messageText, 'user');
          $inputField.val('');
          $sendButton.prop('disabled', true);
          
          // Show typing indicator
          var $typingIndicator = showTypingIndicator();

          // AJAX call to the chatbot controller.
          $.ajax({
            url: chatbotUrl,
            type: 'POST',
            data: JSON.stringify({ message: messageText }),
            contentType: 'application/json; charset=utf-8',
            dataType: 'json',
            success: function (response) {
              // Remove typing indicator
              removeTypingIndicator($typingIndicator);
              
              // Add a small delay for a more natural feeling
              setTimeout(function() {
                if (response.reply) {
                  addMessage(response.reply, 'bot');
                }
                else {
                  addMessage('Sorry, I received an empty response.', 'bot');
                }
              }, 300);
            },
            error: function (xhr, status, error) {
              // Remove typing indicator
              removeTypingIndicator($typingIndicator);
              
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
              $sendButton.prop('disabled', false);
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
        
        // Store chatbot state in localStorage
        var chatbotState = {
          isFirstVisit: true,
          getState: function() {
            var state = localStorage.getItem('drupalxAIChatbotState');
            return state ? JSON.parse(state) : { firstVisit: true };
          },
          saveState: function(data) {
            localStorage.setItem('drupalxAIChatbotState', JSON.stringify(data));
          }
        };
        
        // Show welcome message only on first visit
        var state = chatbotState.getState();
        if (state.firstVisit) {
          // Give a slight delay for a more natural feeling
          setTimeout(function() {
            addMessage("👋 Hello! I'm your DrupalX AI assistant. How can I help you today? Ask me to create a landing page or help with other content!", "bot");
            chatbotState.saveState({firstVisit: false});
          }, 500);
        }
        
        // Add typing indicator functionality
        function showTypingIndicator() {
          var $typingIndicator = $('<div class="message bot typing-indicator"><span></span><span></span><span></span></div>');
          $messagesContainer.append($typingIndicator);
          $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
          return $typingIndicator;
        }
        
        function removeTypingIndicator($indicator) {
          $indicator.remove();
        }
        
        // Add keyboard accessibility to toggle and close buttons
        $toggle.attr('tabindex', '0').on('keydown', function(e) {
          if (e.which === 13 || e.which === 32) { // Enter or Space key
            $(this).click();
            e.preventDefault();
          }
        });
        
        $closeBtn.on('keydown', function(e) {
          if (e.which === 13 || e.which === 32) { // Enter or Space key
            $(this).click();
            e.preventDefault();
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
