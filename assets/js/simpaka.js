document.addEventListener('DOMContentLoaded', () => {
  // Auto-dismiss alert setelah 5 detik
  document.querySelectorAll('.alert-dismissible').forEach((el) => {
    setTimeout(() => {
      if (window.bootstrap && bootstrap.Alert) {
        bootstrap.Alert.getOrCreateInstance(el).close();
      }
    }, 5000);
  });
});
