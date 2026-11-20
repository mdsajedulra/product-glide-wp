/**
 * Voice-note recorder for the WhatsApp inbox.
 *
 * WhatsApp plays a file as a voice note only when it is OGG with the Opus codec.
 * Chrome / Edge cannot record OGG with MediaRecorder, so there the microphone is
 * encoded with WebCodecs (AudioEncoder, Opus) and wrapped in an OGG file here.
 * Firefox records OGG/Opus natively; Safari falls back to MP4/AAC (sent as an
 * audio file).
 *
 * PGVoice.start() → recorder { stop() → Promise<Blob>, cancel(), seconds() }
 */
(function () {
	'use strict';

	/* ---------- OGG writer ---------- */

	var CRC = (function () {
		var t = new Uint32Array(256);
		for (var i = 0; i < 256; i++) {
			var r = i << 24;
			for (var j = 0; j < 8; j++) { r = (r & 0x80000000) ? ((r << 1) ^ 0x04c11db7) : (r << 1); }
			t[i] = r >>> 0;
		}
		return t;
	})();
	function crc32(bytes) {
		var c = 0;
		for (var i = 0; i < bytes.length; i++) { c = ((c << 8) ^ CRC[((c >>> 24) ^ bytes[i]) & 0xff]) >>> 0; }
		return c >>> 0;
	}

	function OggWriter() {
		this.pages = [];
		this.seq = 0;
		this.serial = (Math.random() * 0xffffffff) >>> 0;
		this.pending = [];   // packets waiting for the current page
		this.granule = 0;
	}
	OggWriter.prototype.page = function (packets, granule, flags) {
		var lacing = [], size = 0;
		packets.forEach(function (p) {
			var n = p.length;
			while (n >= 255) { lacing.push(255); n -= 255; }
			lacing.push(n);
			size += p.length;
		});
		var h = new Uint8Array(27 + lacing.length + size), dv = new DataView(h.buffer);
		h.set([0x4f, 0x67, 0x67, 0x53], 0); // "OggS"
		h[4] = 0; h[5] = flags;
		// 64-bit granule position (fits in 53 bits for any realistic recording).
		dv.setUint32(6, granule % 0x100000000, true);
		dv.setUint32(10, Math.floor(granule / 0x100000000), true);
		dv.setUint32(14, this.serial, true);
		dv.setUint32(18, this.seq++, true);
		dv.setUint32(22, 0, true);
		h[26] = lacing.length;
		h.set(lacing, 27);
		var o = 27 + lacing.length;
		packets.forEach(function (p) { h.set(p, o); o += p.length; });
		dv.setUint32(22, crc32(h), true);
		this.pages.push(h);
	};
	OggWriter.prototype.head = function (channels, rate, preskip) {
		var head = new Uint8Array(19), dv = new DataView(head.buffer);
		head.set([0x4f, 0x70, 0x75, 0x73, 0x48, 0x65, 0x61, 0x64], 0); // OpusHead
		head[8] = 1; head[9] = channels;
		dv.setUint16(10, preskip, true);
		dv.setUint32(12, rate, true);
		dv.setUint16(16, 0, true);
		head[18] = 0;
		this.page([head], 0, 0x02);
		var vendor = 'ProductGlide', tags = new Uint8Array(8 + 4 + vendor.length + 4), tv = new DataView(tags.buffer);
		tags.set([0x4f, 0x70, 0x75, 0x73, 0x54, 0x61, 0x67, 0x73], 0); // OpusTags
		tv.setUint32(8, vendor.length, true);
		for (var i = 0; i < vendor.length; i++) { tags[12 + i] = vendor.charCodeAt(i); }
		tv.setUint32(12 + vendor.length, 0, true);
		this.page([tags], 0, 0);
	};
	OggWriter.prototype.add = function (packet, samples) {
		this.granule += samples;
		this.pending.push(packet);
		var segs = this.pending.reduce(function (n, p) { return n + Math.floor(p.length / 255) + 1; }, 0);
		if (segs > 200 || this.pending.length >= 50) { this.flush(false); }
	};
	OggWriter.prototype.flush = function (last) {
		if (!this.pending.length && !last) { return; }
		this.page(this.pending, this.granule, last ? 0x04 : 0);
		this.pending = [];
	};
	OggWriter.prototype.blob = function () {
		this.flush(true);
		return new Blob(this.pages, { type: 'audio/ogg' });
	};

	/* ---------- recorders ---------- */

	function mediaRecorder(stream, mime) {
		var chunks = [], rec = new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 32000 });
		rec.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
		rec.start(500);
		return {
			stop: function () {
				return new Promise(function (resolve) {
					rec.onstop = function () { resolve(new Blob(chunks, { type: mime.split(';')[0] })); };
					rec.stop();
				});
			},
			abort: function () { try { rec.stop(); } catch (e) {} }
		};
	}

	function webcodecsRecorder(stream) {
		var RATE = 48000;
		var ctx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: RATE });
		var src = ctx.createMediaStreamSource(stream);
		var proc = ctx.createScriptProcessor(4096, 1, 1);
		var ogg = new OggWriter(), failed = null, frames = 0;
		ogg.head(1, RATE, 312);
		var enc = new AudioEncoder({
			output: function (chunk) {
				var b = new Uint8Array(chunk.byteLength);
				chunk.copyTo(b);
				var samples = chunk.duration ? Math.round(chunk.duration * RATE / 1e6) : 960;
				ogg.add(b, samples);
			},
			error: function (e) { failed = e; }
		});
		enc.configure({ codec: 'opus', sampleRate: RATE, numberOfChannels: 1, bitrate: 32000 });
		proc.onaudioprocess = function (e) {
			if (enc.state !== 'configured') { return; }
			var pcm = new Float32Array(e.inputBuffer.getChannelData(0));
			var data = new AudioData({ format: 'f32', sampleRate: RATE, numberOfFrames: pcm.length, numberOfChannels: 1, timestamp: Math.round(frames * 1e6 / RATE), data: pcm });
			frames += pcm.length;
			enc.encode(data);
			data.close();
		};
		src.connect(proc);
		proc.connect(ctx.destination); // ScriptProcessor only runs while connected (it outputs silence)
		function close() {
			try { proc.disconnect(); src.disconnect(); } catch (e) {}
			return ctx.close().catch(function () {});
		}
		return {
			stop: function () {
				close();
				return enc.flush().then(function () {
					enc.close();
					if (failed) { throw failed; }
					return ogg.blob();
				});
			},
			abort: function () { close(); try { enc.close(); } catch (e) {} }
		};
	}

	function pick() {
		if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
			return { kind: 'mr', mime: 'audio/ogg;codecs=opus' };
		}
		if (window.AudioEncoder && window.AudioData) {
			return { kind: 'wc' };
		}
		var mp4 = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];
		for (var i = 0; i < mp4.length; i++) {
			if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(mp4[i])) { return { kind: 'mr', mime: mp4[i] }; }
		}
		return null;
	}

	function start() {
		var how = pick();
		if (!how || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			return Promise.reject(new Error('This browser cannot record voice messages. Please use Chrome, Edge or Firefox.'));
		}
		var check = how.kind === 'wc' && AudioEncoder.isConfigSupported
			? AudioEncoder.isConfigSupported({ codec: 'opus', sampleRate: 48000, numberOfChannels: 1 }).then(function (r) { if (!r.supported) { throw new Error('Opus recording is not supported in this browser.'); } })
			: Promise.resolve();
		return check.then(function () {
			return navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } });
		}).then(function (stream) {
			var rec = how.kind === 'wc' ? webcodecsRecorder(stream) : mediaRecorder(stream, how.mime);
			var t0 = Date.now();
			function release() { stream.getTracks().forEach(function (t) { t.stop(); }); }
			return {
				seconds: function () { return Math.floor((Date.now() - t0) / 1000); },
				stop: function () {
					return rec.stop().then(function (blob) {
						release();
						var ext = blob.type === 'audio/ogg' ? 'ogg' : 'm4a';
						return new File([blob], 'voice-' + Date.now() + '.' + ext, { type: blob.type });
					}, function (e) { release(); throw e; });
				},
				cancel: function () { rec.abort(); release(); }
			};
		});
	}

	window.PGVoice = { start: start, supported: function () { return !!pick(); } };
})();
