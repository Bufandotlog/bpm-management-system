const THREE_BASE_URL = 'https://cdn.jsdelivr.net/npm/three@0.184.0';

function ensureThreeImportMap() {
    if (document.querySelector('script[data-bpm-three-importmap]')) {
        return;
    }

    const importMap = document.createElement('script');
    importMap.type = 'importmap';
    importMap.setAttribute('data-bpm-three-importmap', 'true');
    importMap.textContent = JSON.stringify({
        imports: {
            three: `${THREE_BASE_URL}/build/three.module.js`,
            'three/webgpu': `${THREE_BASE_URL}/build/three.webgpu.js`,
            'three/tsl': `${THREE_BASE_URL}/build/three.tsl.js`,
        },
    });
    document.head.appendChild(importMap);
}

function smoothBands(field, radius) {
    const tolerance = 1.5 / 255;
    const smoothRadius = 2;
    const original = field.values.slice();

    function blurAxis(source, target, { width, height, radius: blurRadius }) {
        const span = blurRadius * 2 + 1;
        for (let row = 0; row < height; row++) {
            const offset = row * width;
            let sum = source[offset] * blurRadius;
            for (let column = 0; column <= blurRadius; column++) {
                sum += source[offset + Math.min(column, width - 1)];
            }
            for (let column = 0; column < width; column++) {
                target[column * height + row] = sum / span;
                sum -= source[offset + Math.max(column - blurRadius, 0)];
                sum += source[offset + Math.min(column + blurRadius + 1, width - 1)];
            }
        }
    }

    function boxBlur(currentField, blurRadius) {
        if (blurRadius < 1) return;
        const transposed = new Float32Array(currentField.values.length);
        blurAxis(currentField.values, transposed, { width: currentField.width, height: currentField.height, radius: blurRadius });
        blurAxis(transposed, currentField.values, { width: currentField.height, height: currentField.width, radius: blurRadius });
    }

    boxBlur(field, radius);
    for (let i = 0; i < field.values.length; i++) {
        field.values[i] = Math.min(
            Math.max(field.values[i], original[i] - tolerance),
            original[i] + tolerance,
        );
    }
    boxBlur(field, smoothRadius);
}

async function createRelightingRenderer(container, imageUrl, depthUrl) {
    if (!('gpu' in navigator)) {
        console.warn('[bpm-relighting] WebGPU unsupported. Using static hero image fallback.');
        return null;
    }

    ensureThreeImportMap();

    const [threeModule, tslModule] = await Promise.all([
        import('three/webgpu'),
        import('three/tsl'),
    ]);

    const {
        AgXToneMapping,
        AmbientLight,
        Color,
        DataTexture,
        DataUtils,
        HalfFloatType,
        LinearFilter,
        Mesh,
        MeshPhongNodeMaterial,
        OrthographicCamera,
        PlaneGeometry,
        PointLight,
        RedFormat,
        Scene,
        SRGBColorSpace,
        Texture,
        TextureLoader,
        WebGPURenderer,
    } = threeModule;

    const {
        Loop,
        Fn,
        cameraFar,
        cameraNear,
        float,
        luminance,
        modelScale,
        positionView,
        positionWorld,
        screenSize,
        screenUV,
        step,
        texture,
        uniform,
        vec2,
        vec3,
        viewZToOrthographicDepth,
    } = tslModule;

    const mapNode = texture(new Texture());
    const depthNode = texture(new Texture());

    const renderTarget = document.createElement('canvas');
    renderTarget.width = 1;
    renderTarget.height = 1;
    const renderContext = renderTarget.getContext('2d', { willReadFrequently: true });

    const smoothDepthMap = new DataTexture(
        new Uint16Array([DataUtils.toHalfFloat(0.5)]),
        1,
        1,
        RedFormat,
        HalfFloatType,
    );
    smoothDepthMap.minFilter = LinearFilter;
    smoothDepthMap.magFilter = LinearFilter;
    smoothDepthMap.needsUpdate = true;
    const smoothDepthNode = texture(smoothDepthMap);

    const uDisplacementScale = uniform(5.2);
    const uNormalScale = uniform(4.3);
    const uDetailScale = uniform(4.1);
    const uShadowIntensity = uniform(0.96);
    const uShadowSoftness = uniform(0.076);

    const coverScaleNode = Fn(() => {
        const viewAspect = screenSize.x.div(screenSize.y).toVar();
        const mapSize = vec2(mapNode.size()).toVar();
        const imageAspect = mapSize.x.div(mapSize.y).toVar();

        return imageAspect.greaterThan(viewAspect).select(
            vec2(viewAspect.div(imageAspect), 1),
            vec2(1, imageAspect.div(viewAspect)),
        );
    });

    const coverUv = Fn(() =>
        screenUV.flipY().sub(0.5).mul(coverScaleNode()).add(0.5),
    );

    const depthGradient = Fn(([vUv, stepNode]) => {
        const alongX = vec2(stepNode.x, 0);
        const alongY = vec2(0, stepNode.y);

        const left = smoothDepthNode.sample(vUv.sub(alongX)).r;
        const right = smoothDepthNode.sample(vUv.add(alongX)).r;
        const bottom = smoothDepthNode.sample(vUv.sub(alongY)).r;
        const top = smoothDepthNode.sample(vUv.add(alongY)).r;

        return vec2(right.sub(left), top.sub(bottom)).mul(0.5);
    });

    const detailGradient = Fn(([vUv, stepNode]) => {
        const alongX = vec2(stepNode.x, 0);
        const alongY = vec2(0, stepNode.y);
        const map = (uvNode) => mapNode.sample(uvNode).level(3).rgb;

        const left = luminance(map(vUv.sub(alongX)));
        const right = luminance(map(vUv.add(alongX)));
        const bottom = luminance(map(vUv.sub(alongY)));
        const top = luminance(map(vUv.add(alongY)));

        return vec2(right.sub(left), top.sub(bottom)).mul(0.5);
    });

    const normalNode = Fn(([vUv]) => {
        const stepNode = vec2(3).div(vec2(smoothDepthNode.size()));

        const slope = depthGradient(vUv, stepNode)
            .mul(coverScaleNode())
            .div(stepNode.mul(modelScale.xy))
            .mul(uDisplacementScale)
            .mul(uNormalScale);

        const detail = detailGradient(
            vUv,
            vec2(8).div(vec2(mapNode.size())),
        )
            .mul(uDetailScale)
            .mul(4);

        const shape = vec3(slope.x.negate(), slope.y.negate(), float(1));
        return shape.add(vec3(detail.x.negate(), detail.y.negate(), 0)).normalize();
    });

    const diffuseNode = Fn(([vUv, depth]) =>
        mapNode.sample(vUv).rgb.mul(step(float(0), depth)),
    );

    const shadowNode = Fn(([vUv, depth]) => {
        const surfacePosition = vec3(
            positionWorld.xy,
            depth.sub(1).mul(uDisplacementScale),
        );
        const surfaceToLight = lightPositionNode.sub(surfacePosition);
        const lightDirection = surfaceToLight.div(surfaceToLight.length().max(float(0.001)));
        const surfaceDepth = smoothDepthNode.sample(vUv).r;
        const remainingDepth = surfaceDepth.oneMinus();

        const rayOffset = lightDirection.xy
            .div(lightDirection.z.max(float(0.15)))
            .mul(uDisplacementScale)
            .mul(coverScaleNode())
            .div(modelScale.xy)
            .mul(remainingDepth);

        const maxOcclusion = float(0).toVar();

        Loop(12, ({ i }) => {
            const rayProgress = float(i).add(1).div(12);
            const rayDepth = surfaceDepth.add(remainingDepth.mul(rayProgress));
            const blockerDepth = smoothDepthNode.sample(vUv.add(rayOffset.mul(rayProgress))).r;
            const sampleSoftness = uShadowSoftness.mul(rayProgress.mul(3).add(1));
            const sampleOcclusion = blockerDepth
                .sub(rayDepth)
                .div(sampleSoftness)
                .clamp(0, 1);
            maxOcclusion.assign(maxOcclusion.max(sampleOcclusion));
        });

        const lightFacingMask = lightDirection.z.greaterThan(0).select(1, 0);
        return maxOcclusion.mul(uShadowIntensity).mul(lightFacingMask).oneMinus();
    });

    async function setMap(url) {
        const loader = new TextureLoader();
        const map = await loader.loadAsync(url);
        map.colorSpace = SRGBColorSpace;
        mapNode.value.dispose();
        mapNode.value = map;
    }

    function setDepthImage(image) {
        const scale = Math.min(1, 1024 / image.width);
        const width = Math.max(1, Math.round(image.width * scale));
        const height = Math.max(1, Math.round(image.height * scale));

        renderTarget.width = width;
        renderTarget.height = height;
        renderContext.setTransform(1, 0, 0, -1, 0, height);
        renderContext.drawImage(image, 0, 0, width, height);

        const { data } = renderContext.getImageData(0, 0, width, height);
        const values = new Float32Array(width * height);
        for (let i = 0; i < values.length; i++) {
            values[i] = data[i * 4] / 255;
        }

        smoothBands({ values, width, height }, Math.round((1.3 / 100) * width));
        const halfFloats = new Uint16Array(values.length);
        for (let i = 0; i < halfFloats.length; i++) {
            halfFloats[i] = DataUtils.toHalfFloat(values[i]);
        }

        smoothDepthMap.dispose();
        smoothDepthMap.image = { data: halfFloats, width, height };
        smoothDepthMap.needsUpdate = true;
    }

    async function setDepth(url) {
        const loader = new TextureLoader();
        const map = await loader.loadAsync(url);
        depthNode.value.dispose();
        depthNode.value = map;
        setDepthImage(map.image);
    }

    const renderer = new WebGPURenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(container.clientWidth || window.innerWidth, container.clientHeight || window.innerHeight);
    renderer.toneMapping = AgXToneMapping;
    container.append(renderer.domElement);

    await renderer.init();
    await Promise.all([setMap(imageUrl), setDepth(depthUrl)]);

    const scene = new Scene();
    scene.background = new Color('#000000');
    scene.background = null;

    const camera = new OrthographicCamera();
    camera.position.set(0, 0, 5);

    const pointLight = new PointLight('#f2bd55', 2.9, 3.2, 0.9);
    pointLight.position.set(1.5, 0.9, 1.1);
    const lightPositionNode = uniform(pointLight.position.clone());
    const ambientLight = new AmbientLight('#8f7442', 0.22);
    scene.add(pointLight, ambientLight);

    if (renderer.toneMappingExposure !== undefined) {
        renderer.toneMappingExposure = 1.12;
    }

    const vUv = coverUv();
    const depth = depthNode.sample(vUv).r;
    const material = new MeshPhongNodeMaterial({ specular: 0x000000, transparent: true, opacity: 1 });
    material.colorNode = diffuseNode(vUv, depth);
    material.normalNode = normalNode(vUv);
    material.aoNode = shadowNode(vUv, depth);
    material.depthNode = viewZToOrthographicDepth(
        positionView.z.add(depth.sub(1).mul(uDisplacementScale)),
        cameraNear,
        cameraFar,
    );

    const plane = new Mesh(new PlaneGeometry(1, 1), material);
    scene.add(plane);

    function onResize() {
        const width = container.clientWidth || window.innerWidth;
        const height = container.clientHeight || window.innerHeight;
        const aspect = width / height;

        camera.top = 4 * 0.5;
        camera.bottom = -camera.top;
        camera.right = camera.top * aspect;
        camera.left = -camera.right;
        camera.updateProjectionMatrix();
        plane.scale.set(4 * aspect, 4, 1);
        renderer.setSize(width, height);
    }

    const onPointerMove = (event) => {
        const ndcX = (event.clientX / window.innerWidth) * 2 - 1;
        const ndcY = -((event.clientY / window.innerHeight) * 2 - 1);
        pointLight.position.x = ndcX * camera.right;
        pointLight.position.y = ndcY * camera.top;
        lightPositionNode.value.copy(pointLight.position);
    };

    onResize();
    window.addEventListener('pointermove', onPointerMove, { passive: true });
    window.addEventListener('resize', onResize);

    renderer.setAnimationLoop(() => renderer.render(scene, camera));

    return {
        cleanup() {
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('resize', onResize);
            renderer.setAnimationLoop(null);
            renderer.dispose();
            if (renderer.domElement && renderer.domElement.parentNode) {
                renderer.domElement.remove();
            }
            if (mapNode.value && mapNode.value.dispose) mapNode.value.dispose();
            if (depthNode.value && depthNode.value.dispose) depthNode.value.dispose();
            if (smoothDepthMap && smoothDepthMap.dispose) smoothDepthMap.dispose();
            if (material && material.dispose) material.dispose();
            if (plane && plane.geometry && plane.geometry.dispose) plane.geometry.dispose();
        },
    };
}

export function initRelighting() {
    const relightingPages = [
        'page-index',
        'page-berita',
        'page-hukum',
        'page-hukum-detail',
        'page-kepengurusan',
        'page-detail-menteri',
        'page-arsip',
        'page-kontak',
    ];
    if (!relightingPages.some((pageClass) => document.body.classList.contains(pageClass))) {
        return null;
    }

    const hero = document.querySelector('.hero');
    if (!hero) {
        return null;
    }

    if (document.getElementById('bpm-relighting')) {
        return null;
    }

    const imageUrl = hero.dataset.relightingImage;
    const depthUrl = hero.dataset.relightingDepth;
    if (!imageUrl || !depthUrl) {
        return null;
    }

    const container = document.createElement('div');
    container.id = 'bpm-relighting';
    container.className = 'bpm-relighting';
    const visual = hero.querySelector('.hero-visual') || hero;
    visual.prepend(container);

    createRelightingRenderer(container, imageUrl, depthUrl)
        .then((instance) => {
            if (!instance) {
                container.remove();
                return;
            }
            container.dataset.instance = 'active';
            const cleanup = () => {
                instance.cleanup();
            };
            if (!window.__bpmRelightingCleanup) {
                window.__bpmRelightingCleanup = cleanup;
            }
            window.addEventListener('beforeunload', cleanup, { once: true });
        })
        .catch((error) => {
            console.warn('[bpm-relighting] Failed to initialize. Falling back to static hero image.', error);
            container.remove();
        });

    return container;
}
