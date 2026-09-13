import { createHash } from 'node:crypto';
import { cp, lstat, mkdir, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const packageRoot = join(projectRoot, 'node_modules', '@mediapipe', 'tasks-vision');
const assetRoot = join(projectRoot, 'public', 'assets', 'vendor', 'mediapipe-tasks-vision-1.0.1');
const MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite';
const PACKAGE_VERSION = '1.0.1';
const PACKAGE_INTEGRITY = 'sha512-rvRE2FmAZ6ZxKSw7wq+e+jQDpN3t1B/tD2mJz9SmAzb1msoDkd4dMoE4wAh8Z30Um0PQwLiHr9QtomhmXk3aUQ==';
const MAX_MODEL_BYTES = 20 * 1024 * 1024;
const APACHE_LICENSE = "\n                                 Apache License\n                           Version 2.0, January 2004\n                        https://www.apache.org/licenses/\n\n   TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION\n\n   1. Definitions.\n\n      \"License\" shall mean the terms and conditions for use, reproduction,\n      and distribution as defined by Sections 1 through 9 of this document.\n\n      \"Licensor\" shall mean the copyright owner or entity authorized by\n      the copyright owner that is granting the License.\n\n      \"Legal Entity\" shall mean the union of the acting entity and all\n      other entities that control, are controlled by, or are under common\n      control with that entity. For the purposes of this definition,\n      \"control\" means (i) the power, direct or indirect, to cause the\n      direction or management of such entity, whether by contract or\n      otherwise, or (ii) ownership of fifty percent (50%) or more of the\n      outstanding shares, or (iii) beneficial ownership of such entity.\n\n      \"You\" (or \"Your\") shall mean an individual or Legal Entity\n      exercising permissions granted by this License.\n\n      \"Source\" form shall mean the preferred form for making modifications,\n      including but not limited to software source code, documentation\n      source, and configuration files.\n\n      \"Object\" form shall mean any form resulting from mechanical\n      transformation or translation of a Source form, including but\n      not limited to compiled object code, generated documentation,\n      and conversions to other media types.\n\n      \"Work\" shall mean the work of authorship, whether in Source or\n      Object form, made available under the License, as indicated by a\n      copyright notice that is included in or attached to the work\n      (an example is provided in the Appendix below).\n\n      \"Derivative Works\" shall mean any work, whether in Source or Object\n      form, that is based on (or derived from) the Work and for which the\n      editorial revisions, annotations, elaborations, or other modifications\n      represent, as a whole, an original work of authorship. For the purposes\n      of this License, Derivative Works shall not include works that remain\n      separable from, or merely link (or bind by name) to the interfaces of,\n      the Work and Derivative Works thereof.\n\n      \"Contribution\" shall mean any work of authorship, including\n      the original version of the Work and any modifications or additions\n      to that Work or Derivative Works thereof, that is intentionally\n      submitted to Licensor for inclusion in the Work by the copyright owner\n      or by an individual or Legal Entity authorized to submit on behalf of\n      the copyright owner. For the purposes of this definition, \"submitted\"\n      means any form of electronic, verbal, or written communication sent\n      to the Licensor or its representatives, including but not limited to\n      communication on electronic mailing lists, source code control systems,\n      and issue tracking systems that are managed by, or on behalf of, the\n      Licensor for the purpose of discussing and improving the Work, but\n      excluding communication that is conspicuously marked or otherwise\n      designated in writing by the copyright owner as \"Not a Contribution.\"\n\n      \"Contributor\" shall mean Licensor and any individual or Legal Entity\n      on behalf of whom a Contribution has been received by Licensor and\n      subsequently incorporated within the Work.\n\n   2. Grant of Copyright License. Subject to the terms and conditions of\n      this License, each Contributor hereby grants to You a perpetual,\n      worldwide, non-exclusive, no-charge, royalty-free, irrevocable\n      copyright license to reproduce, prepare Derivative Works of,\n      publicly display, publicly perform, sublicense, and distribute the\n      Work and such Derivative Works in Source or Object form.\n\n   3. Grant of Patent License. Subject to the terms and conditions of\n      this License, each Contributor hereby grants to You a perpetual,\n      worldwide, non-exclusive, no-charge, royalty-free, irrevocable\n      (except as stated in this section) patent license to make, have made,\n      use, offer to sell, sell, import, and otherwise transfer the Work,\n      where such license applies only to those patent claims licensable\n      by such Contributor that are necessarily infringed by their\n      Contribution(s) alone or by combination of their Contribution(s)\n      with the Work to which such Contribution(s) was submitted. If You\n      institute patent litigation against any entity (including a\n      cross-claim or counterclaim in a lawsuit) alleging that the Work\n      or a Contribution incorporated within the Work constitutes direct\n      or contributory patent infringement, then any patent licenses\n      granted to You under this License for that Work shall terminate\n      as of the date such litigation is filed.\n\n   4. Redistribution. You may reproduce and distribute copies of the\n      Work or Derivative Works thereof in any medium, with or without\n      modifications, and in Source or Object form, provided that You\n      meet the following conditions:\n\n      (a) You must give any other recipients of the Work or\n          Derivative Works a copy of this License; and\n\n      (b) You must cause any modified files to carry prominent notices\n          stating that You changed the files; and\n\n      (c) You must retain, in the Source form of any Derivative Works\n          that You distribute, all copyright, patent, trademark, and\n          attribution notices from the Source form of the Work,\n          excluding those notices that do not pertain to any part of\n          the Derivative Works; and\n\n      (d) If the Work includes a \"NOTICE\" text file as part of its\n          distribution, then any Derivative Works that You distribute must\n          include a readable copy of the attribution notices contained\n          within such NOTICE file, excluding those notices that do not\n          pertain to any part of the Derivative Works, in at least one\n          of the following places: within a NOTICE text file distributed\n          as part of the Derivative Works; within the Source form or\n          documentation, if provided along with the Derivative Works; or,\n          within a display generated by the Derivative Works, if and\n          wherever such third-party notices normally appear. The contents\n          of the NOTICE file are for informational purposes only and\n          do not modify the License. You may add Your own attribution\n          notices within Derivative Works that You distribute, alongside\n          or as an addendum to the NOTICE text from the Work, provided\n          that such additional attribution notices cannot be construed\n          as modifying the License.\n\n      You may add Your own copyright statement to Your modifications and\n      may provide additional or different license terms and conditions\n      for use, reproduction, or distribution of Your modifications, or\n      for any such Derivative Works as a whole, provided Your use,\n      reproduction, and distribution of the Work otherwise complies with\n      the conditions stated in this License.\n\n   5. Submission of Contributions. Unless You explicitly state otherwise,\n      any Contribution intentionally submitted for inclusion in the Work\n      by You to the Licensor shall be under the terms and conditions of\n      this License, without any additional terms or conditions.\n      Notwithstanding the above, nothing herein shall supersede or modify\n      the terms of any separate license agreement you may have executed\n      with Licensor regarding such Contributions.\n\n   6. Trademarks. This License does not grant permission to use the trade\n      names, trademarks, service marks, or product names of the Licensor,\n      except as required for reasonable and customary use in describing the\n      origin of the Work and reproducing the content of the NOTICE file.\n\n   7. Disclaimer of Warranty. Unless required by applicable law or\n      agreed to in writing, Licensor provides the Work (and each\n      Contributor provides its Contributions) on an \"AS IS\" BASIS,\n      WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or\n      implied, including, without limitation, any warranties or conditions\n      of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A\n      PARTICULAR PURPOSE. You are solely responsible for determining the\n      appropriateness of using or redistributing the Work and assume any\n      risks associated with Your exercise of permissions under this License.\n\n   8. Limitation of Liability. In no event and under no legal theory,\n      whether in tort (including negligence), contract, or otherwise,\n      unless required by applicable law (such as deliberate and grossly\n      negligent acts) or agreed to in writing, shall any Contributor be\n      liable to You for damages, including any direct, indirect, special,\n      incidental, or consequential damages of any character arising as a\n      result of this License or out of the use or inability to use the\n      Work (including but not limited to damages for loss of goodwill,\n      work stoppage, computer failure or malfunction, or any and all\n      other commercial damages or losses), even if such Contributor\n      has been advised of the possibility of such damages.\n\n   9. Accepting Warranty or Additional Liability. While redistributing\n      the Work or Derivative Works thereof, You may choose to offer,\n      and charge a fee for, acceptance of support, warranty, indemnity,\n      or other liability obligations and/or rights consistent with this\n      License. However, in accepting such obligations, You may act only\n      on Your own behalf and on Your sole responsibility, not on behalf\n      of any other Contributor, and only if You agree to indemnify,\n      defend, and hold each Contributor harmless for any liability\n      incurred by, or claims asserted against, such Contributor by reason\n      of your accepting any such warranty or additional liability.\n\n   END OF TERMS AND CONDITIONS\n\n   APPENDIX: How to apply the Apache License to your work.\n\n      To apply the Apache License to your work, attach the following\n      boilerplate notice, with the fields enclosed by brackets \"[]\"\n      replaced with your own identifying information. (Don't include\n      the brackets!)  The text should be enclosed in the appropriate\n      comment syntax for the file format. We also recommend that a\n      file or class name and description of purpose be included on the\n      same \"printed page\" as the copyright notice for easier\n      identification within third-party archives.\n\n   Copyright [yyyy] [name of copyright owner]\n\n   Licensed under the Apache License, Version 2.0 (the \"License\");\n   you may not use this file except in compliance with the License.\n   You may obtain a copy of the License at\n\n       https://www.apache.org/licenses/LICENSE-2.0\n\n   Unless required by applicable law or agreed to in writing, software\n   distributed under the License is distributed on an \"AS IS\" BASIS,\n   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.\n   See the License for the specific language governing permissions and\n   limitations under the License.\n";
const ALLOWED = [
  'LICENSE', 'manifest.json', 'vision_bundle.mjs',
  'models/blaze_face_short_range_float16.tflite',
  'wasm/vision_wasm_internal.js', 'wasm/vision_wasm_internal.wasm',
  'wasm/vision_wasm_nosimd_internal.js', 'wasm/vision_wasm_nosimd_internal.wasm',
];
const HASHED = ALLOWED.filter(path => path !== 'manifest.json');

const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
const posix = path => path.split(sep).join('/');

async function downloadModel() {
  const response = await fetch(MODEL_URL, { redirect: 'follow' });
  if (!response.ok || new URL(response.url).hostname !== 'storage.googleapis.com' || !response.body) {
    throw new Error('Model download failed validation.');
  }
  const declaredLength = Number(response.headers.get('content-length'));
  if (Number.isFinite(declaredLength) && declaredLength > MAX_MODEL_BYTES) throw new Error('Model exceeds size limit.');
  const reader = response.body.getReader();
  const chunks = [];
  let total = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > MAX_MODEL_BYTES) throw new Error('Model exceeds size limit.');
    chunks.push(value);
  }
  const output = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) { output.set(chunk, offset); offset += chunk.byteLength; }
  return output;
}

async function listFiles(root) {
  const rootStat = await lstat(root);
  if (!rootStat.isDirectory() || rootStat.isSymbolicLink()) throw new Error('Invalid asset root.');
  const output = [];
  async function visit(directory) {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const absolute = join(directory, entry.name);
      const item = await lstat(absolute);
      if (item.isSymbolicLink()) throw new Error('Links and reparse points are forbidden.');
      if (item.isDirectory()) { await visit(absolute); continue; }
      if (!item.isFile()) throw new Error('Only regular files are allowed.');
      output.push(posix(relative(root, absolute)));
    }
  }
  await visit(root);
  return output.sort();
}

async function buildManifest() {
  const files = [];
  for (const path of [...HASHED].sort()) {
    const bytes = await readFile(join(assetRoot, path));
    files.push({ path, size_bytes: bytes.byteLength, sha256: sha256(bytes) });
  }
  return {
    package: {
      name: '@mediapipe/tasks-vision', version: PACKAGE_VERSION,
      source: 'https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.0.1.tgz',
      integrity: PACKAGE_INTEGRITY, license: 'Apache-2.0',
    },
    model: { source: MODEL_URL, revision: 'blaze_face_short_range/float16/1' },
    files,
  };
}

async function importAssets() {
  const packageJson = JSON.parse(await readFile(join(packageRoot, 'package.json'), 'utf8'));
  if (packageJson.name !== '@mediapipe/tasks-vision' || packageJson.version !== PACKAGE_VERSION) {
    throw new Error('The installed MediaPipe package is not exactly 1.0.1.');
  }
  await rm(assetRoot, { recursive: true, force: true });
  await mkdir(join(assetRoot, 'models'), { recursive: true });
  await mkdir(join(assetRoot, 'wasm'), { recursive: true });
  await cp(join(packageRoot, 'vision_bundle.mjs'), join(assetRoot, 'vision_bundle.mjs'));
  for (const path of HASHED.filter(path => path.startsWith('wasm/'))) await cp(join(packageRoot, path), join(assetRoot, path));
  await writeFile(join(assetRoot, 'LICENSE'), APACHE_LICENSE, 'utf8');
  await writeFile(join(assetRoot, 'models', 'blaze_face_short_range_float16.tflite'), await downloadModel());
  await writeFile(join(assetRoot, 'manifest.json'), `${JSON.stringify(await buildManifest(), null, 2)}\n`, 'utf8');
  if (!await verifyAssets()) throw new Error('Imported MediaPipe assets did not verify.');
}

export async function verifyAssets() {
  try {
    const actual = await listFiles(assetRoot);
    if (actual.length !== ALLOWED.length || actual.some((path, index) => path !== [...ALLOWED].sort()[index])) return false;
    const manifest = JSON.parse(await readFile(join(assetRoot, 'manifest.json'), 'utf8'));
    if (manifest?.package?.name !== '@mediapipe/tasks-vision' || manifest?.package?.version !== PACKAGE_VERSION || manifest?.package?.source !== 'https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.0.1.tgz' || manifest?.package?.integrity !== PACKAGE_INTEGRITY || manifest?.package?.license !== 'Apache-2.0') return false;
    if (manifest?.model?.source !== MODEL_URL || manifest?.model?.revision !== 'blaze_face_short_range/float16/1' || !Array.isArray(manifest.files) || manifest.files.length !== HASHED.length) return false;
    const recorded = new Map(manifest.files.map(file => [file.path, file]));
    if (recorded.size !== HASHED.length || HASHED.some(path => !recorded.has(path))) return false;
    for (const path of HASHED) {
      const absolute = resolve(assetRoot, path);
      if (!absolute.startsWith(`${assetRoot}${sep}`) || (await lstat(absolute)).isSymbolicLink()) return false;
      const bytes = await readFile(absolute);
      const file = recorded.get(path);
      if (!file || file.size_bytes !== bytes.byteLength || typeof file.sha256 !== 'string' || file.sha256 !== sha256(bytes)) return false;
    }
    return true;
  } catch { return false; }
}

const command = process.argv[2];
if (command === 'import') await importAssets();
else if (command === 'verify') { if (!await verifyAssets()) process.exitCode = 1; }
else { process.exitCode = 2; }
