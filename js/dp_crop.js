// filename: js/dp_crop.js
// DP cropper for CareOfID: zoom + drag inside a circle, export to PNG
// FIX: saved image uses the SAME size as the on-screen circle,
// so what you see in the circle is exactly what gets saved.

document.addEventListener('DOMContentLoaded', function () {
  var fileInput       = document.getElementById('dp_file');
  var cropArea        = document.getElementById('dp_crop_area');
  var cropImg         = document.getElementById('dp_crop_img');
  var zoomInput       = document.getElementById('dp_zoom');
  var useBtn          = document.getElementById('dp_use_btn');
  var cancelBtn       = document.getElementById('dp_cancel_btn');
  var cropCtrls       = document.getElementById('dp_crop_controls');
  var cropBtns        = document.getElementById('dp_crop_buttons');
  var dpCropped       = document.getElementById('dp_cropped');
  var currentWrapper  = document.getElementById('dp_current_wrapper');
  var currentPreview  = document.getElementById('dp_current_preview');

  if (!fileInput || !cropArea || !cropImg || !useBtn || !cancelBtn || !dpCropped) {
    return;
  }

  // JS active
  useBtn.style.opacity = '1';
  useBtn.style.cursor  = 'pointer';

  var state = {
    imgLoaded: false,
    baseScale: 1,   // scale to fit image in circle
    userScale: 1,   // extra zoom from slider
    offsetX: 0,
    offsetY: 0,
    dragging: false,
    dragStartX: 0,
    dragStartY: 0,
    lastOffsetX: 0,
    lastOffsetY: 0,
    circleSize: 220 // will be set from DOM
  };

  function totalScale() {
    return state.baseScale * state.userScale;
  }

  function updateTransform() {
    var s = totalScale();
    var t = 'translate(-50%, -50%) translate(' +
      state.offsetX + 'px,' + state.offsetY + 'px) scale(' + s + ')';
    cropImg.style.transform = t;
  }

  function resetState() {
    state.userScale = 1;
    state.offsetX = 0;
    state.offsetY = 0;
    state.dragging = false;
    state.dragStartX = 0;
    state.dragStartY = 0;
    state.lastOffsetX = 0;
    state.lastOffsetY = 0;
    if (zoomInput) zoomInput.value = '1';
    updateTransform();
  }

  // When user selects a file
  fileInput.addEventListener('change', function () {
    var file = fileInput.files && fileInput.files[0];
    if (!file) return;

    // reset button label & stored data
    dpCropped.value = '';
    useBtn.textContent = 'Use this picture';
    useBtn.style.backgroundColor = '';

    var reader = new FileReader();
    reader.onload = function (e) {
      cropImg.onload = function () {
        // Get the actual circle size in pixels (CSS width of dp-crop-area)
        var cs = cropArea.offsetWidth;
        if (!cs || cs <= 0) cs = 220;
        state.circleSize = cs;

        var iw = cropImg.naturalWidth  || 1;
        var ih = cropImg.naturalHeight || 1;

        // Base scale so image COVERS the circle (no empty edges)
        var sx = cs / iw;
        var sy = cs / ih;
        state.baseScale = Math.max(sx, sy);

        state.imgLoaded = true;
        resetState();

        cropArea.style.display = 'block';
        if (cropCtrls) cropCtrls.style.display = 'block';
        if (cropBtns)  cropBtns.style.display  = 'flex';
      };
      cropImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });

  // Zoom control: userScale (0.5–2, configured in HTML)
  if (zoomInput) {
    zoomInput.addEventListener('input', function () {
      var v = parseFloat(zoomInput.value);
      if (!isNaN(v) && v > 0) {
        state.userScale = v;
        updateTransform();
      }
    });
  }

  function getClientXY(ev) {
    if (ev.touches && ev.touches.length > 0) {
      return { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
    }
    return { x: ev.clientX, y: ev.clientY };
  }

  function onPointerDown(ev) {
    if (!state.imgLoaded) return;
    var p = getClientXY(ev);
    state.dragging = true;
    state.dragStartX = p.x;
    state.dragStartY = p.y;
    state.lastOffsetX = state.offsetX;
    state.lastOffsetY = state.offsetY;
    cropImg.style.cursor = 'grabbing';
    ev.preventDefault();
  }

  function onPointerMove(ev) {
    if (!state.dragging) return;
    var p = getClientXY(ev);
    var dx = p.x - state.dragStartX;
    var dy = p.y - state.dragStartY;
    state.offsetX = state.lastOffsetX + dx;
    state.offsetY = state.lastOffsetY + dy;
    updateTransform();
    ev.preventDefault();
  }

  function onPointerUp() {
    if (!state.dragging) return;
    state.dragging = false;
    cropImg.style.cursor = 'grab';
  }

  cropImg.addEventListener('mousedown', onPointerDown);
  cropImg.addEventListener('touchstart', onPointerDown);
  window.addEventListener('mousemove', onPointerMove);
  window.addEventListener('touchmove', onPointerMove);
  window.addEventListener('mouseup', onPointerUp);
  window.addEventListener('touchend', onPointerUp);

  // Cancel button: hide crop UI, reset
  cancelBtn.addEventListener('click', function () {
    cropArea.style.display = 'none';
    if (cropCtrls) cropCtrls.style.display = 'none';
    if (cropBtns)  cropBtns.style.display  = 'flex';
    fileInput.value = '';
    dpCropped.value = '';
    useBtn.textContent = 'Use this picture';
    useBtn.style.backgroundColor = '';
  });

  // "Use this picture" → render EXACTLY what is in the circle to a canvas
  useBtn.addEventListener('click', function () {
    // Visual feedback
    useBtn.textContent = 'Use this picture ✓';
    useBtn.style.backgroundColor = '#e0f5e0';

    if (!state.imgLoaded || !cropImg.naturalWidth || !cropImg.naturalHeight) {
      // click is wired but no image
      return;
    }

    var canvasSize = state.circleSize; // 🔴 key fix: same as circle
    var canvas = document.createElement('canvas');
    canvas.width = canvasSize;
    canvas.height = canvasSize;
    var ctx = canvas.getContext('2d');

    // White square background (your circle is via CSS border-radius)
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvasSize, canvasSize);

    var imgW = cropImg.naturalWidth;
    var imgH = cropImg.naturalHeight;
    var s    = totalScale();
    var drawW = imgW * s;
    var drawH = imgH * s;

    var centerX = canvasSize / 2;
    var centerY = canvasSize / 2;

    var drawX = centerX + state.offsetX - (drawW / 2);
    var drawY = centerY + state.offsetY - (drawH / 2);

    ctx.drawImage(cropImg, drawX, drawY, drawW, drawH);

    var dataUrl = canvas.toDataURL('image/png');
    dpCropped.value = dataUrl;

    // Update preview (small circle above)
    if (currentPreview) {
      currentPreview.src = dataUrl;
      currentPreview.style.display = 'block';
    }
    if (currentWrapper) {
      currentWrapper.style.display = 'flex';
    }

    // Hide crop UI; the saved DP will match the preview image
    cropArea.style.display = 'none';
    if (cropCtrls) cropCtrls.style.display = 'none';
  });
});
