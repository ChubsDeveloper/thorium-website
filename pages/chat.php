<?php
/**
 * pages/chat.php
 * Page template - full-screen chat interface
 */
declare(strict_types=1);

$page_title = 'Live Chat - Thorium WoW';
$page_description = 'Join the conversation with fellow Thorium WoW players in our live chat system.';

include themed_partial_path('head');
include themed_partial_path('header');

// Check if user is logged in
$user = auth_user();
if (!$user) {
    echo '<div class="container mx-auto px-6 py-20 text-center">';
    echo '<h1 class="text-3xl font-bold mb-4">Login Required</h1>';
    echo '<p class="text-neutral-400 mb-8">You must be logged in to access the chat.</p>';
    echo '<a href="' . e(base_url('login')) . '" class="btn btn-warm">Login</a>';
    echo '</div>';
    include themed_partial_path('footer');
    return;
}
?>

<div class="min-h-screen bg-gradient-to-b from-black/50 to-black/80 pt-20">
  <div class="container mx-auto px-6 py-8">
    <div class="mb-6 text-center">
      <h1 class="h-display text-4xl font-bold mb-2">Live Chat</h1>
      <p class="text-neutral-400">Connect with the Thorium WoW community</p>
    </div>
    
    <?php if (module_enabled('chat')): ?>
      <?php module_render('chat'); ?>
    <?php else: ?>
      <div class="text-center py-20">
        <h2 class="text-2xl font-bold mb-4">Chat Unavailable</h2>
        <p class="text-neutral-400">The chat system is currently disabled.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
/* Full-screen chat optimizations */
#chat-section {
  padding-top: 0;
  padding-bottom: 2rem;
}

#chat-section .container {
  max-width: 6xl;
}

#chat-messages {
  height: 60vh;
  min-height: 400px;
}

@media (max-width: 768px) {
  #chat-messages {
    height: 50vh;
    min-height: 300px;
  }
}
</style>

<?php include themed_partial_path('footer'); ?>
