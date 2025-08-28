(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.drupalxAiChatbot = {
    attach: function (context, settings) {
      // Initialize the off-canvas chatbot
      $(once('drupalx-ai-chatbot-toggle', '.drupalx-ai-chatbot-toggle', context)).each(function () {
        var $toggle = $(this);
        var $overlay = $('.drupalx-ai-chatbot-overlay');
        var $closeBtn = $overlay.find('.drupalx-ai-chatbot-close');
        var $messagesContainer = $overlay.find('.drupalx-ai-chatbot-messages');
        var $inputField = $overlay.find('.drupalx-ai-chatbot-input input[type="text"]');
        var $sendButton = $overlay.find('.drupalx-ai-chatbot-input button');
        var chatbotUrl = drupalSettings.drupalx_ai.chatbot_url;

        // Toggle chatbot visibility
        $toggle.on('click', function () {
          $overlay.addClass('active');
          // Focus the input field when opening
          setTimeout(function() {
            $inputField.focus();
          }, 300); // Delay to allow animation to complete
        });

        // Close button functionality
        $closeBtn.on('click', function () {
          $overlay.removeClass('active');
        });

        // Close when clicking overlay backdrop
        $overlay.on('click', function (e) {
          if (e.target === this) {
            $overlay.removeClass('active');
          }
        });

        // Close on Escape key
        $(document).on('keydown', function(e) {
          if (e.key === 'Escape' && $overlay.hasClass('active')) {
            $overlay.removeClass('active');
          }
        });

        function addMessage(text, type) {
          // Hide intro section when first message is added
          var $intro = $overlay.find('.drupalx-ai-chatbot-intro');
          if ($intro.is(':visible')) {
            $intro.slideUp(300);
          }

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
              setTimeout(function () {
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
              
              // Try to get error message from server response in order of preference
              if (xhr.responseJSON) {
                if (xhr.responseJSON.reply) {
                  errorMessage = xhr.responseJSON.reply;
                } else if (xhr.responseJSON.error) {
                  errorMessage = xhr.responseJSON.error;
                } else if (xhr.responseJSON.message) {
                  errorMessage = xhr.responseJSON.message;
                }
              } else if (xhr.responseText) {
                try {
                  var errorData = JSON.parse(xhr.responseText);
                  if (errorData.reply) {
                    errorMessage = errorData.reply;
                  } else if (errorData.error) {
                    errorMessage = errorData.error;
                  } else if (errorData.message) {
                    errorMessage = errorData.message;
                  }
                } catch (e) {
                  // The responseText was not JSON - use first 100 chars
                  if (xhr.responseText.length > 0) {
                    errorMessage = 'Error: ' + xhr.responseText.substring(0, 100);
                  }
                }
              }
              
              // Don't add "Error: " prefix if the message already contains it
              if (!errorMessage.startsWith('Error:') && !errorMessage.startsWith('API ')) {
                errorMessage = 'Error: ' + errorMessage;
              }
              
              // Add the error message with HTML support for links
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

        // Welcome message now appears every time the behavior attaches.
        // Give a slight delay for a more natural feeling.
        setTimeout(function () {
          addMessage("👋 Hello! I'm your AI Page Builder. Describe the landing page you\'d like me to create!", "bot");
        }, 500);

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
        $toggle.attr('tabindex', '0').on('keydown', function (e) {
          if (e.which === 13 || e.which === 32) { // Enter or Space key
            $(this).click();
            e.preventDefault();
          }
        });

        $closeBtn.on('keydown', function (e) {
          if (e.which === 13 || e.which === 32) { // Enter or Space key
            $(this).click();
            e.preventDefault();
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
