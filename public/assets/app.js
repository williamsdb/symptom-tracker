'use strict';
const strength = document.getElementById('strength');
const output = document.getElementById('strength-output');
if (strength && output) strength.addEventListener('input', () => { output.value = `${strength.value} / 10`; });
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
