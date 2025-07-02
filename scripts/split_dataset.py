# -*- coding: utf-8 -*-
# Versi "Grandmaster" v6.3 - Flexible Augmentation Switch
# Menambahkan saklar ON/OFF eksplisit untuk augmentasi, memberikan kontrol penuh.

import os
import shutil
from pathlib import Path
import pandas as pd
import numpy as np
from collections import defaultdict, deque
from sklearn.model_selection import StratifiedShuffleSplit
import cv2
import albumentations as A
import uuid

class UltimateGrandmasterExpander:
    """
    Kelas final yang fleksibel, bisa melakukan ekspansi masif atau hanya splitting,
    tergantung pada konfigurasi.
    """
    def __init__(self, **config):
        for key, value in config.items(): setattr(self, key, value)
        self.data_root = Path(self.DATA_ROOT)
        self.output_dir = Path(self.OUTPUT_DIR)
        # State
        self.source_anns_df = None
        self.master_image_plan_df = None
        self.path_map = {}
        self.image_to_anns_map = {}
        self.single_annotation_images = set()

    def _validate_source_data(self):
        print("\n📊 TAHAP 1: VALIDASI DATA SUMBER...")
        all_dfs = [pd.read_csv(self.data_root / 'annotations' / f'{f}_annotations.csv') for f in self.IMAGE_SOURCE_FOLDERS]
        self.source_anns_df = pd.concat(all_dfs, ignore_index=True)
        self.source_anns_df['label'] = self.source_anns_df.apply(self.MAP_LABEL_FUNC, axis=1)
        self.source_anns_df = self.source_anns_df.dropna(subset=['label']).copy()

        self.path_map = {p.name: p for f in self.IMAGE_SOURCE_FOLDERS for e in self.IMAGE_EXTENSIONS for p in (self.data_root / f).glob(e)}
        self.image_to_anns_map = self.source_anns_df.groupby('filename').apply(lambda df: df.to_dict('records')).to_dict()

        ann_counts_per_image = self.source_anns_df['filename'].value_counts()
        self.single_annotation_images = set(ann_counts_per_image[ann_counts_per_image == 1].index)

        print(f"✅ Data sumber tervalidasi. Total Anotasi: {len(self.source_anns_df)}. Total Gambar Unik: {len(self.image_to_anns_map)}.")
        if self.PERFORM_AUGMENTATION:
             print(f"   -> Ditemukan {len(self.single_annotation_images)} gambar dengan 1 anotasi (akan menjadi sumber augmentasi).")

    def _plan_and_find_optimal_split(self):
        original_images_df = self.source_anns_df[['filename', 'label']].drop_duplicates()
        image_plan_list = [{'source_filename': r['filename'], 'label': r['label'], 'is_augmented': False} for _, r in original_images_df.iterrows()]

        # --- PERUBAHAN UTAMA V6.3: Saklar Augmentasi ---
        if self.PERFORM_AUGMENTATION:
            print(f"\n🧠 TAHAP 2.1: MEMBUAT BLUEPRINT EKSPANSI (Target Anotasi per Kelas: {self.TARGET_ANNOTATION_COUNT_PER_CLASS})...")
            ann_counts_by_class = self.source_anns_df['label'].value_counts()

            for class_name in self.CLASSES:
                current_ann_count = ann_counts_by_class.get(class_name, 0)
                n_augmentations_needed = max(0, self.TARGET_ANNOTATION_COUNT_PER_CLASS - current_ann_count)
                print(f"     - Kelas '{class_name}': {current_ann_count} anotasi. Rencana augmentasi: {n_augmentations_needed}")

                if n_augmentations_needed > 0:
                    all_class_images = set(self.source_anns_df[self.source_anns_df['label'] == class_name]['filename'].unique())
                    valid_sources_for_aug = list(all_class_images.intersection(self.single_annotation_images))

                    if valid_sources_for_aug:
                        print(f"       -> Sumber augmentasi valid untuk kelas '{class_name}': {len(valid_sources_for_aug)} gambar.")
                        chosen_sources = np.random.choice(valid_sources_for_aug, n_augmentations_needed, replace=True)
                        image_plan_list.extend([{'source_filename': s, 'label': class_name, 'is_augmented': True} for s in chosen_sources])
                    else:
                        print(f"       -> ⚠️ PERINGATAN: Tidak ada sumber valid untuk augmentasi kelas '{class_name}'. Augmentasi dilewati.")
        else:
            print("\n🧠 TAHAP 2.1: AUGMENTASI DINONAKTIFKAN. Hanya melakukan split pada data asli.")

        self.master_image_plan_df = pd.DataFrame(image_plan_list)
        print(f"  -> Blueprint Selesai. Total gambar akan diproses: {len(self.master_image_plan_df)}.")

        print(f"\n🧠 TAHAP 2.2: MENCARI SPLIT OPTIMAL DARI {self.FINETUNE_TRIALS} PERCOBAAN...")
        # (Logika finetune tidak diubah)
        best_split = {'score': float('inf'), 'indices': None, 'planned_counts': {}}
        ratios = self.STRATEGY_RATIOS
        for i in range(self.FINETUNE_TRIALS):
            current_random_state = self.RANDOM_STATE + i
            splitter1 = StratifiedShuffleSplit(n_splits=1, test_size=(ratios['valid'] + ratios['test']), random_state=current_random_state)
            train_indices, temp_indices = next(splitter1.split(self.master_image_plan_df, self.master_image_plan_df['label']))
            temp_df = self.master_image_plan_df.iloc[temp_indices]
            relative_test_size = ratios['test'] / (ratios['valid'] + ratios['test'])
            splitter2 = StratifiedShuffleSplit(n_splits=1, test_size=relative_test_size, random_state=current_random_state)
            valid_relative_indices, test_relative_indices = next(splitter2.split(temp_df, temp_df['label']))
            current_valid_indices, current_test_indices = temp_df.iloc[valid_relative_indices].index, temp_df.iloc[test_relative_indices].index
            def count_annotations(indices): return sum(1 if self.master_image_plan_df.loc[idx]['is_augmented'] else len(self.image_to_anns_map.get(self.master_image_plan_df.loc[idx]['source_filename'],[])) for idx in indices)
            ann_counts = {'train':count_annotations(train_indices), 'valid':count_annotations(current_valid_indices), 'test':count_annotations(current_test_indices)}
            total_anns = sum(ann_counts.values())
            if total_anns==0: continue
            img_counts = {'train':len(train_indices), 'valid':len(current_valid_indices), 'test':len(current_test_indices)}
            img_pct, ann_pct = {k:v/sum(img_counts.values()) for k,v in img_counts.items()}, {k:v/total_anns for k,v in ann_counts.items()}
            img_error, ann_error = sum(abs(img_pct[s]-ratios[s]) for s in ratios), sum(abs(ann_pct[s]-ratios[s]) for s in ratios)
            combined_score = (img_error * self.IMAGE_RATIO_WEIGHT) + (ann_error * (1 - self.IMAGE_RATIO_WEIGHT))
            if combined_score < best_split['score']:
                best_split.update({'score': combined_score, 'indices': (train_indices, current_valid_indices, current_test_indices), 'planned_counts': {'img': img_counts, 'ann': ann_counts}})

        pc = best_split['planned_counts']
        print(f"  -> Blueprint Optimal Ditemukan. Rencana Eksekusi:")
        print(f"     - Train: {pc['img']['train']} Gambar / {pc['ann']['train']} Anotasi")
        print(f"     - Valid: {pc['img']['valid']} Gambar / {pc['ann']['valid']} Anotasi")
        print(f"     - Test:  {pc['img']['test']} Gambar / {pc['ann']['test']} Anotasi")
        train_indices, valid_indices, test_indices = best_split['indices']
        self.master_image_plan_df.loc[train_indices, 'set'] = 'train'
        self.master_image_plan_df.loc[valid_indices, 'set'] = 'valid'
        self.master_image_plan_df.loc[test_indices, 'set'] = 'test'
        print("✅ Blueprint final telah ditetapkan.")

    def _execute_operations(self):
        # (Logika eksekusi tidak diubah)
        print("\n🚀 TAHAP 3: EKSEKUSI DENGAN PENJAMINAN (SELF-CORRECTING)...")
        if self.output_dir.exists(): shutil.rmtree(self.output_dir)
        final_annotations_list = []
        for split_name in ['train', 'valid', 'test']:
            print(f"  -> Memproses set '{split_name}'...")
            split_dir = self.output_dir / split_name; split_dir.mkdir(parents=True, exist_ok=True)
            set_plan_df = self.master_image_plan_df[self.master_image_plan_df['set'] == split_name]
            original_plan = set_plan_df[~set_plan_df['is_augmented']]
            augment_plan = set_plan_df[set_plan_df['is_augmented']]
            print(f"     - Menyalin {len(original_plan.drop_duplicates('source_filename'))} gambar asli...")
            for _, plan_row in original_plan.iterrows():
                source_filename_short = Path(plan_row['source_filename']).name
                dest_path = split_dir / source_filename_short
                if not dest_path.exists(): shutil.copy2(self.path_map[source_filename_short], dest_path)
                for ann_row_dict in self.image_to_anns_map.get(plan_row['source_filename'], []):
                    new_ann_row = pd.Series(ann_row_dict).copy(); new_ann_row['set'], new_ann_row['filename'] = split_name, f"{split_name}/{source_filename_short}"
                    final_annotations_list.append(pd.DataFrame([new_ann_row]))

            target_augment_count = len(augment_plan)
            if target_augment_count > 0:
                successful_augments = 0
                augment_sources = deque(augment_plan.itertuples())
                print(f"     - Membuat {target_augment_count} augmentasi (sumber: hanya gbr dgn 1 anotasi)...")
                while successful_augments < target_augment_count:
                    if not augment_sources: print("\n     - ⚠️ PERINGATAN: Semua sumber augmentasi habis."); break
                    plan_row = augment_sources.popleft()
                    source_filename_short = Path(plan_row.source_filename).name; source_image_path = self.path_map.get(source_filename_short)
                    if not source_image_path: continue
                    source_ann_row = pd.Series(self.image_to_anns_map.get(plan_row.source_filename, [])[0])
                    image = cv2.imread(str(source_image_path)); image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
                    cx,cy,w,h = source_ann_row[['bbox_cx','bbox_cy','bbox_w','bbox_h']]
                    x_min,y_min,x_max,y_max = max(0.,cx-w/2),max(0.,cy-h/2),min(1.,cx+w/2),min(1.,cy+h/2)
                    if x_min >= x_max or y_min >= y_max: continue
                    transformed = self.AUGMENTATION_PIPELINE(image=image, bboxes=[[x_min, y_min, x_max, y_max]], category_ids=[0])
                    if transformed['bboxes']:
                        successful_augments += 1
                        new_filename = f"{Path(source_filename_short).stem}_aug_{uuid.uuid4().hex[:6]}.jpg"
                        cv2.imwrite(str(split_dir / new_filename), cv2.cvtColor(transformed['image'], cv2.COLOR_RGB2BGR))
                        nx_min, ny_min, nx_max, ny_max = transformed['bboxes'][0]
                        new_ann_row = source_ann_row.copy(); new_ann_row['set'], new_ann_row['filename'] = split_name, f"{split_name}/{new_filename}"
                        new_ann_row[['bbox_cx','bbox_cy']] = (nx_min+nx_max)/2, (ny_min+ny_max)/2
                        new_ann_row[['bbox_w','bbox_h']] = nx_max-nx_min, ny_max-ny_min
                        final_annotations_list.append(pd.DataFrame([new_ann_row]))
                    else:
                        augment_sources.append(plan_row)
                print(f"     - Target {target_augment_count} augmentasi untuk set '{split_name}' tercapai.")

        final_annotations_df = pd.concat(final_annotations_list, ignore_index=True)
        for split_name in ['train', 'valid', 'test']:
            split_ann_df = final_annotations_df[final_annotations_df['set'] == split_name]
            output_csv_path = self.output_dir / 'annotations' / f'{split_name}_annotations.csv'
            output_csv_path.parent.mkdir(parents=True, exist_ok=True)
            split_ann_df.to_csv(output_csv_path, index=False, columns=self.OUTPUT_HEADER, header=True)
        print("✅ Eksekusi dataset terjamin selesai.")

    def _generate_final_report(self):
        # (Logika pelaporan tidak diubah)
        print("\n\n======================================================================")
        print("🎉 PROSES SELESAI: LAPORAN GRANDMASTER v6.3")
        print("======================================================================")
        final_anns_df = pd.concat([pd.read_csv(self.output_dir/'annotations'/f'{s}_annotations.csv') for s in self.STRATEGY_RATIOS])
        final_anns_df['label'] = final_anns_df.apply(self.MAP_LABEL_FUNC, axis=1)
        total_images_final, total_annotations_final = len(final_anns_df['filename'].unique()), len(final_anns_df)
        print(f"\n📊 --- DISTRIBUSI GAMBAR (Total Unik: {total_images_final}) --- 📊")
        img_dist = final_anns_df.drop_duplicates(subset=['filename'])['set'].value_counts()
        for name in sorted(self.STRATEGY_RATIOS.keys()):
            count = img_dist.get(name, 0); print(f"▶️ {name.upper()} SET: {count} gambar ({(count/total_images_final)*100:.2f}%)")
        print(f"\n\n📦 --- DISTRIBUSI ANOTASI (Total: {total_annotations_final}) --- 📦")
        for split_name in sorted(self.STRATEGY_RATIOS.keys()):
            split_anns_df = final_anns_df[final_anns_df['set'] == split_name]
            count = len(split_anns_df); dist = split_anns_df['label'].value_counts()
            print(f"\n▶️ {split_name.upper()} SET: {count} anotasi ({(count/total_annotations_final)*100:.2f}%)")
            for cls_name in self.CLASSES: print(f"     - {cls_name.ljust(15)}: {dist.get(cls_name, 0)} anotasi")
        print("\n======================================================================")
        print(f"\nDataset Anda telah siap di: \n{self.output_dir.resolve()}")

    def run(self):
        self._validate_source_data()
        self._plan_and_find_optimal_split()
        self._execute_operations()
        self._generate_final_report()

if __name__ == "__main__":
    config = {
        'DATA_ROOT': 'storage/app/dataset',
        'OUTPUT_DIR': 'storage/app/dataset/split',
        'IMAGE_SOURCE_FOLDERS': ['train', 'valid', 'test'],
        'IMAGE_EXTENSIONS': ['*.jpg', '*.jpeg', '*.png', '*.webp'],
        'CLASSES': ['belum_matang', 'matang'],
        'OUTPUT_HEADER': ['filename', 'set', 'detection_class', 'ripeness_class', 'bbox_cx', 'bbox_cy', 'bbox_w', 'bbox_h'],
        'MAP_LABEL_FUNC': lambda row: 'matang' if row['ripeness_class'] == 'ripe' else 'belum_matang',
        'RANDOM_STATE': 42,
        'AUGMENTATION_PIPELINE': A.Compose([
            A.HorizontalFlip(p=0.5), A.RandomBrightnessContrast(p=0.8), A.ShiftScaleRotate(p=0.75),
            A.GaussNoise(p=0.2), A.OneOf([A.MotionBlur(p=0.2), A.MedianBlur(p=0.1), A.Blur(p=0.1)], p=0.25),
        ], bbox_params=A.BboxParams(format='albumentations', min_visibility=0.2, label_fields=['category_ids'])),

        # --- SAKLAR UTAMA ---
        # Set ke True untuk melakukan ekspansi dataset.
        # Set ke False untuk HANYA melakukan splitting pada data asli tanpa augmentasi.
        'PERFORM_AUGMENTATION': True,

        # Konfigurasi ini hanya digunakan jika PERFORM_AUGMENTATION = True
        'TARGET_ANNOTATION_COUNT_PER_CLASS': 2500,

        # Konfigurasi yang selalu digunakan
        'FINETUNE_TRIALS': 500,
        'STRATEGY_RATIOS': {'train': 0.70, 'valid': 0.15, 'test': 0.15},
        'IMAGE_RATIO_WEIGHT': 0.5,
    }
    UltimateGrandmasterExpander(**config).run()
